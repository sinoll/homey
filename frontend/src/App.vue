<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { renderAsync as renderDocx } from 'docx-preview'
import { api, type Account, type CloudFile, type CloudFolder, type CloudShare, type SharedDetails, type TrashedFile } from './api'

type PreviewKind = 'image' | 'pdf' | 'docx' | 'text' | 'audio' | 'video'

interface FilePreview {
  file: CloudFile
  kind: PreviewKind
  loading: boolean
  error: string
  url: string
  text: string
  isPublic: boolean
}

interface DialogOption {
  value: string
  label: string
}

interface ActionDialog {
  title: string
  description?: string
  inputType: 'text' | 'datetime-local' | 'select' | 'confirm' | 'link'
  inputLabel?: string
  options?: DialogOption[]
  submitLabel?: string
  destructive?: boolean
}

const TOKEN_KEY = 'yii-cloud-token'
const EMAIL_KEY = 'yii-cloud-email'
const token = ref(localStorage.getItem(TOKEN_KEY) ?? '')
const email = ref(localStorage.getItem(EMAIL_KEY) ?? '')
const files = ref<CloudFile[]>([])
const trashedFiles = ref<TrashedFile[]>([])
const folders = ref<CloudFolder[]>([])
const folderPath = ref<CloudFolder[]>([])
const shares = ref<CloudShare[]>([])
const showShares = ref(false)
const showTrash = ref(false)
const publicShareToken = ref(location.pathname.match(/^\/share\/([^/]+)/)?.[1] ?? '')
const publicDetails = ref<SharedDetails | null>(null)
const busy = ref(false)
const loadingFiles = ref(false)
const authMode = ref<'login' | 'register'>('login')
const authEmail = ref('')
const password = ref('')
const search = ref('')
const errorMessage = ref('')
const successMessage = ref('')
const activeDialog = ref<ActionDialog | null>(null)
const dialogValue = ref('')
const dialogError = ref('')
const dialogFeedback = ref('')
const filePreview = ref<FilePreview | null>(null)
const docxPreviewContent = ref<HTMLDivElement | null>(null)
const activeContextMenu = ref<string | null>(null)
const uploadInput = ref<HTMLInputElement | null>(null)
const searchInput = ref<HTMLInputElement | null>(null)
let resolveDialog: ((value: string | null) => void) | null = null
let searchTimeout: ReturnType<typeof setTimeout> | undefined
let fileRequestId = 0

const visibleFiles = computed(() => {
  if (search.value.trim()) return files.value
  const query = search.value.trim().toLocaleLowerCase()
  if (!query) return files.value
  return files.value.filter((file) => file.name.toLocaleLowerCase().includes(query))
})
const visibleFolders = computed(() => {
  if (search.value.trim()) return []
  const query = search.value.trim().toLocaleLowerCase()
  if (!query) return folders.value
  return folders.value.filter((folder) => folder.name.toLocaleLowerCase().includes(query))
})
const visibleTrash = computed(() => {
  const query = search.value.trim().toLocaleLowerCase()
  if (!query) return trashedFiles.value
  return trashedFiles.value.filter((file) => file.name.toLocaleLowerCase().includes(query))
})
const currentFolderId = computed(() => folderPath.value.at(-1)?.id ?? null)
const displayBreadcrumbs = computed(() => folderPath.value)
const totalBytes = computed(() => files.value.reduce((total, file) => total + file.size, 0))
const displayName = computed(() => email.value.split('@')[0] || '用户')
const webdavUrl = computed(() => new URL('/dav/', window.location.origin).toString())

function openActionDialog(config: ActionDialog, initialValue = ''): Promise<string | null> {
  if (resolveDialog) resolveDialog(null)
  activeDialog.value = config
  dialogValue.value = initialValue
  dialogError.value = ''
  dialogFeedback.value = ''

  return new Promise((resolve) => {
    resolveDialog = resolve
  })
}

function defaultShareExpiry(): string {
  const date = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000)
  const twoDigits = (value: number): string => String(value).padStart(2, '0')

  return `${date.getFullYear()}-${twoDigits(date.getMonth() + 1)}-${twoDigits(date.getDate())}`
    + `T${twoDigits(date.getHours())}:${twoDigits(date.getMinutes())}`
}

function closeActionDialog(value: string | null = null): void {
  const resolve = resolveDialog
  resolveDialog = null
  activeDialog.value = null
  dialogValue.value = ''
  dialogError.value = ''
  dialogFeedback.value = ''
  resolve?.(value)
}

function toggleContextMenu(type: 'file' | 'folder', id: string): void {
  const menuId = `${type}-${id}`
  activeContextMenu.value = activeContextMenu.value === menuId ? null : menuId
}

function closeContextMenu(): void {
  activeContextMenu.value = null
}

function submitActionDialog(): void {
  if (!activeDialog.value) return
  if (activeDialog.value.inputType === 'text' && !dialogValue.value.trim()) {
    dialogError.value = '请填写此项。'
    return
  }
  closeActionDialog(activeDialog.value.inputType === 'confirm' ? 'confirm' : dialogValue.value)
}

async function copyShareLink(): Promise<void> {
  try {
    await navigator.clipboard.writeText(dialogValue.value)
    dialogFeedback.value = '链接已复制。'
  } catch {
    dialogFeedback.value = '无法自动复制，请选中上方链接并手动复制。'
  }
}

function formatBytes(size: number): string {
  if (size < 1024) return `${size} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = size / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit += 1
  }
  return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`
}

async function moveFile(file: CloudFile): Promise<void> {
  const destinations: Array<{ id: string | null; path: string }> = [{ id: null, path: '我的文件（根目录）' }]
  const collect = async (parentId: string | null, prefix: string): Promise<void> => {
    const children = await api.listFolders(token.value, parentId)
    for (const folder of children) {
      const path = prefix ? `${prefix} / ${folder.name}` : folder.name
      destinations.push({ id: folder.id, path })
      await collect(folder.id, path)
    }
  }
  busy.value = true
  errorMessage.value = ''
  try {
    await collect(null, '')
    const selection = await openActionDialog({
      title: '移动文件',
      description: `选择「${file.name}」的新位置。`,
      inputType: 'select',
      inputLabel: '目标位置',
      options: destinations.map((destination) => ({
        value: destination.id ?? '',
        label: destination.path,
      })),
      submitLabel: '移动',
    }, file.folderId ?? currentFolderId.value ?? '')
    if (selection === null) return
    const target = destinations.find((destination) => (destination.id ?? '') === selection)
    if (!target) return
    await api.moveFile(token.value, file, target.id)
    successMessage.value = '文件已移动。'
    await loadFiles()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '移动文件失败。'
  } finally {
    busy.value = false
  }
}

function formatDate(value?: string): string {
  if (!value) return '刚刚'
  const date = new Date(`${value.replace(' ', 'T')}Z`)
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(date)
}

function fileGlyph(file: CloudFile): string {
  const extension = file.name.split('.').pop()?.toUpperCase() ?? 'FILE'
  if (['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG'].includes(extension)) return 'IMG'
  if (['PDF'].includes(extension)) return 'PDF'
  if (['DOC', 'DOCX', 'TXT', 'MD'].includes(extension)) return 'DOC'
  if (['ZIP', 'RAR', '7Z', 'TAR'].includes(extension)) return 'ZIP'
  return extension.slice(0, 4) || 'FILE'
}

function previewKind(file: CloudFile): PreviewKind | null {
  const extension = file.name.split('.').pop()?.toLocaleLowerCase() ?? ''
  if (['avif', 'bmp', 'gif', 'jpeg', 'jpg', 'png', 'webp'].includes(extension)) return 'image'
  if (extension === 'pdf') return 'pdf'
  if (extension === 'docx') return 'docx'
  if (['csv', 'json', 'log', 'md', 'txt', 'xml', 'yaml', 'yml'].includes(extension)) return 'text'
  if (['aac', 'flac', 'm4a', 'mp3', 'oga', 'ogg', 'opus', 'wav'].includes(extension)) return 'audio'
  if (['m4v', 'mp4', 'mov', 'ogv', 'webm'].includes(extension)) return 'video'
  return null
}

function canPreview(file: CloudFile): boolean {
  return previewKind(file) !== null
}

function closeFilePreview(): void {
  if (filePreview.value?.url) URL.revokeObjectURL(filePreview.value.url)
  filePreview.value = null
}

async function previewFile(file: CloudFile, isPublic = false): Promise<void> {
  const kind = previewKind(file)
  if (!kind) return

  closeFilePreview()
  const preview = reactive<FilePreview>({
    file,
    kind,
    loading: true,
    error: '',
    url: '',
    text: '',
    isPublic,
  })
  filePreview.value = preview

  try {
    const blob = isPublic
      ? await api.previewShared(publicShareToken.value, file)
      : await api.preview(token.value, file)
    if (filePreview.value !== preview) return

    if (kind === 'text') {
      if (file.size > 5 * 1024 * 1024) {
        throw new Error('文本文件超过 5 MB，无法在浏览器中安全预览。')
      }
      preview.text = await blob.text()
    } else if (kind === 'docx') {
      if (file.size > 20 * 1024 * 1024) {
        throw new Error('Word 文档超过 20 MB，暂不支持在线预览。')
      }
      preview.loading = false
      await nextTick()
      if (!docxPreviewContent.value) throw new Error('无法初始化 Word 文档预览。')
      await renderDocx(blob, docxPreviewContent.value, docxPreviewContent.value, {
        className: 'cloud-docx',
        breakPages: true,
        renderAltChunks: false,
        renderComments: false,
        useBase64URL: true,
      })
    } else {
      preview.url = URL.createObjectURL(blob)
    }
  } catch (error) {
    if (filePreview.value === preview) {
      preview.error = error instanceof Error ? error.message : '无法预览此文件。'
    }
  } finally {
    preview.loading = false
  }
}

async function loadFiles(): Promise<void> {
  if (!token.value) return
  const requestId = ++fileRequestId
  const searchTerm = search.value.trim()
  loadingFiles.value = true
  errorMessage.value = ''
  try {
    const [nextFiles, nextFolders] = await Promise.all([
      api.listFiles(token.value, currentFolderId.value, searchTerm),
      searchTerm ? Promise.resolve([]) : api.listFolders(token.value, currentFolderId.value),
    ])
    if (requestId !== fileRequestId || searchTerm !== search.value.trim()) return
    files.value = nextFiles
    folders.value = nextFolders
  } catch (error) {
    if (requestId === fileRequestId && searchTerm === search.value.trim()) {
      errorMessage.value = error instanceof Error ? error.message : '读取文件列表失败。'
    }
  } finally {
    if (requestId === fileRequestId && searchTerm === search.value.trim()) loadingFiles.value = false
  }
}

watch(search, () => {
  if (showShares.value || showTrash.value || !token.value) return
  if (searchTimeout !== undefined) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    searchTimeout = undefined
    void loadFiles()
  }, 250)
})

async function submitAuth(): Promise<void> {
  busy.value = true
  errorMessage.value = ''
  try {
    const account = await api.authenticate(
      authEmail.value.trim(),
      password.value,
      authMode.value === 'register',
    )
    saveAccount(account)
    password.value = ''
    await loadFiles()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '登录失败。'
  } finally {
    busy.value = false
  }
}

function saveAccount(account: Account): void {
  token.value = account.token
  email.value = account.email
  localStorage.setItem(TOKEN_KEY, account.token)
  localStorage.setItem(EMAIL_KEY, account.email)
}

function logout(): void {
  token.value = ''
  email.value = ''
  files.value = []
  trashedFiles.value = []
  folders.value = []
  folderPath.value = []
  showShares.value = false
  showTrash.value = false
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(EMAIL_KEY)
  errorMessage.value = ''
  successMessage.value = ''
}

function openPicker(): void {
  uploadInput.value?.click()
}

async function uploadSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const selectedFiles = Array.from(input.files ?? [])
  input.value = ''
  if (!selectedFiles.length || !token.value) return

  busy.value = true
  errorMessage.value = ''
  successMessage.value = ''
  let uploaded = 0
  try {
    for (const file of selectedFiles) {
      await api.upload(token.value, file, currentFolderId.value)
      uploaded += 1
    }
    successMessage.value = uploaded === 1 ? '文件已上传。' : `已上传 ${uploaded} 个文件。`
    await loadFiles()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '上传失败。'
    if (uploaded > 0) await loadFiles()
  } finally {
    busy.value = false
  }
}

async function createFolder(): Promise<void> {
  const name = await openActionDialog({
    title: '新建文件夹',
    description: '请输入新文件夹的名称。',
    inputType: 'text',
    inputLabel: '文件夹名称',
    submitLabel: '创建',
  })
  if (name === null || !name.trim()) return
  busy.value = true
  errorMessage.value = ''
  try {
    await api.createFolder(token.value, name.trim(), currentFolderId.value)
    successMessage.value = `文件夹「${name.trim()}」已创建。`
    await loadFiles()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '创建文件夹失败。'
  } finally {
    busy.value = false
  }
}

async function renameFolder(folder: CloudFolder): Promise<void> {
  const name = await openActionDialog({
    title: '重命名文件夹',
    description: `为「${folder.name}」输入新名称。`,
    inputType: 'text',
    inputLabel: '文件夹名称',
    submitLabel: '保存',
  }, folder.name)
  if (name === null || !name.trim() || name.trim() === folder.name) return
  busy.value = true
  errorMessage.value = ''
  try {
    await api.renameFolder(token.value, folder, name.trim())
    successMessage.value = '文件夹已重命名。'
    await loadFiles()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '重命名失败。'
  } finally {
    busy.value = false
  }
}

async function moveFolder(folder: CloudFolder): Promise<void> {
  const destinations: Array<{ id: string | null; path: string; ancestors: string[] }> = [
    { id: null, path: '我的文件（根目录）', ancestors: [] },
  ]
  const collect = async (parentId: string | null, prefix: string, ancestors: string[]): Promise<void> => {
    const children = await api.listFolders(token.value, parentId)
    for (const child of children) {
      const path = prefix ? `${prefix} / ${child.name}` : child.name
      const pathIds = [...ancestors, child.id]
      destinations.push({ id: child.id, path, ancestors: pathIds })
      await collect(child.id, path, pathIds)
    }
  }
  busy.value = true
  try {
    await collect(null, '', [])
    const choices = destinations
      .filter((destination) => !destination.ancestors.includes(folder.id))
    const selection = await openActionDialog({
      title: '移动文件夹',
      description: `选择「${folder.name}」的新位置。不能移动到自身或其子文件夹。`,
      inputType: 'select',
      inputLabel: '目标位置',
      options: choices.map((destination) => ({
        value: destination.id ?? '',
        label: destination.path,
      })),
      submitLabel: '移动',
    }, folder.parentId ?? '')
    if (selection === null) return
    const target = choices.find((destination) => (destination.id ?? '') === selection)
    if (!target) return
    await api.moveFolder(token.value, folder, target.id)
    successMessage.value = '文件夹已移动。'
    await loadFiles()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '移动文件夹失败。'
  } finally {
    busy.value = false
  }
}

async function deleteFolder(folder: CloudFolder): Promise<void> {
  const confirmed = await openActionDialog({
    title: '删除文件夹',
    description: `确定删除空文件夹「${folder.name}」吗？文件夹内有内容时无法删除。`,
    inputType: 'confirm',
    submitLabel: '删除',
    destructive: true,
  })
  if (confirmed !== 'confirm') return
  busy.value = true
  errorMessage.value = ''
  try {
    await api.deleteFolder(token.value, folder)
    successMessage.value = '文件夹已删除。'
    await loadFiles()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '删除文件夹失败。'
  } finally {
    busy.value = false
  }
}

async function openFolder(folder: CloudFolder): Promise<void> {
  folderPath.value = [...folderPath.value, folder]
  search.value = ''
  await loadFiles()
}

async function navigateToFolder(index: number): Promise<void> {
  folderPath.value = folderPath.value.slice(0, index + 1)
  search.value = ''
  await loadFiles()
}

async function createShare(item: CloudFile | CloudFolder, type: 'file' | 'folder'): Promise<void> {
  const input = await openActionDialog({
    title: '创建只读分享',
    description: '默认 7 天后到期。可修改到期时间，清空则表示链接永不过期。访问者只能查看和下载。',
    inputType: 'datetime-local',
    inputLabel: '到期时间（可选）',
    submitLabel: '创建分享',
  }, defaultShareExpiry())
  if (input === null) return
  let expiresAt: string | null = null
  if (input.trim()) {
    const parsed = new Date(input)
    if (Number.isNaN(parsed.getTime()) || parsed.getTime() <= Date.now()) {
      errorMessage.value = '请填写有效的未来日期时间。'
      return
    }
    expiresAt = parsed.toISOString().replace(/\.\d{3}Z$/, 'Z')
  }

  busy.value = true
  errorMessage.value = ''
  try {
    let created: { id: string; token: string; expiresAt: string | null }
    if (type === 'file') {
      if (!('size' in item)) throw new Error('分享文件数据无效。')
      created = await api.createFileShare(token.value, item, expiresAt)
    } else {
      if (!('parentId' in item)) throw new Error('分享文件夹数据无效。')
      created = await api.createFolderShare(token.value, item, expiresAt)
    }
    const url = new URL(`/share/${created.token}`, location.origin).toString()
    try {
      await navigator.clipboard.writeText(url)
      successMessage.value = '只读分享链接已创建并复制到剪贴板。'
    } catch {
      successMessage.value = '只读分享链接已创建。'
    }
    await openActionDialog({
      title: '分享链接已创建',
      description: '请保存此链接并发送给需要访问的人。任何获得链接的人都可以下载分享内容。',
      inputType: 'link',
      inputLabel: '分享链接',
      submitLabel: '完成',
    }, url)
    if (showShares.value) await loadShares()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '创建分享链接失败。'
  } finally {
    busy.value = false
  }
}

async function loadShares(): Promise<void> {
  if (!token.value) return
  try {
    shares.value = await api.listShares(token.value)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '读取分享列表失败。'
  }
}

async function loadTrash(): Promise<void> {
  if (!token.value) return
  loadingFiles.value = true
  errorMessage.value = ''
  try {
    trashedFiles.value = await api.listTrash(token.value)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '读取回收站失败。'
  } finally {
    loadingFiles.value = false
  }
}

function refreshCurrentView(): void {
  if (showShares.value) void loadShares()
  else if (showTrash.value) void loadTrash()
  else void loadFiles()
}

function openFilesView(): void {
  showShares.value = false
  showTrash.value = false
  folderPath.value = []
  void loadFiles()
}

function openSharesView(): void {
  showShares.value = true
  showTrash.value = false
  void loadShares()
}

function openTrashView(): void {
  showShares.value = false
  showTrash.value = true
  folderPath.value = []
  void loadTrash()
}

async function restoreTrashFile(file: TrashedFile): Promise<void> {
  busy.value = true
  errorMessage.value = ''
  try {
    await api.restoreTrashFile(token.value, file)
    trashedFiles.value = trashedFiles.value.filter((item) => item.id !== file.id)
    successMessage.value = `文件「${file.name}」已恢复。`
    await loadTrash()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '恢复文件失败。'
  } finally {
    busy.value = false
  }
}

async function permanentlyDeleteTrashFile(file: TrashedFile): Promise<void> {
  const confirmed = await openActionDialog({
    title: '永久删除文件',
    description: `确定永久删除「${file.name}」吗？文件内容将被彻底删除，无法恢复。`,
    inputType: 'confirm',
    submitLabel: '永久删除',
    destructive: true,
  })
  if (confirmed !== 'confirm') return

  busy.value = true
  errorMessage.value = ''
  try {
    await api.permanentlyDeleteTrashFile(token.value, file)
    trashedFiles.value = trashedFiles.value.filter((item) => item.id !== file.id)
    successMessage.value = `文件「${file.name}」已永久删除。`
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '永久删除文件失败。'
  } finally {
    busy.value = false
  }
}

async function revokeShare(share: CloudShare): Promise<void> {
  const confirmed = await openActionDialog({
    title: '撤销分享链接',
    description: `确定撤销「${share.name}」的分享链接吗？撤销后，原链接将无法访问。`,
    inputType: 'confirm',
    submitLabel: '撤销分享',
    destructive: true,
  })
  if (confirmed !== 'confirm') return
  busy.value = true
  try {
    await api.deleteShare(token.value, share)
    shares.value = shares.value.filter((item) => item.id !== share.id)
    successMessage.value = '分享链接已撤销。'
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '撤销分享链接失败。'
  } finally {
    busy.value = false
  }
}

async function loadPublicShare(): Promise<void> {
  try {
    publicDetails.value = await api.publicShare(publicShareToken.value)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '分享链接无效或已过期。'
  }
}

async function downloadPublicFile(file: CloudFile): Promise<void> {
  try {
    await api.downloadShared(publicShareToken.value, file)
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '下载失败。'
  }
}

async function downloadPreviewFile(): Promise<void> {
  const preview = filePreview.value
  if (!preview) return
  if (preview.isPublic) await downloadPublicFile(preview.file)
  else await downloadFile(preview.file)
}

async function downloadFile(file: CloudFile): Promise<void> {
  busy.value = true
  errorMessage.value = ''
  try {
    await api.download(token.value, file)
    successMessage.value = `正在下载「${file.name}」。`
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '下载失败。'
  } finally {
    busy.value = false
  }
}

async function deleteFile(file: CloudFile): Promise<void> {
  const confirmed = await openActionDialog({
    title: '删除文件',
    description: `确定删除「${file.name}」吗？文件会移至回收站，可在回收站中恢复或永久删除。`,
    inputType: 'confirm',
    submitLabel: '删除',
    destructive: true,
  })
  if (confirmed !== 'confirm') return
  busy.value = true
  errorMessage.value = ''
  try {
    await api.deleteFile(token.value, file)
    files.value = files.value.filter((item) => item.id !== file.id)
    successMessage.value = '文件已移至回收站，可随时恢复或永久删除。'
    await loadTrash()
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '删除失败。'
  } finally {
    busy.value = false
  }
}

function handleUnauthorized(): void {
  logout()
  errorMessage.value = '登录状态已失效，请重新登录。'
}

function handleSearchShortcut(event: KeyboardEvent): void {
  if (event.key === 'Escape' && activeContextMenu.value) {
    closeContextMenu()
    return
  }
  if (event.key === 'Escape' && filePreview.value) {
    closeFilePreview()
    return
  }
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    searchInput.value?.focus()
  }
}

function handleDocumentClick(event: MouseEvent): void {
  if (event.target instanceof Element && !event.target.closest('.context-menu-container')) {
    closeContextMenu()
  }
}

onMounted(() => {
  window.addEventListener('yii-cloud:unauthorized', handleUnauthorized)
  window.addEventListener('keydown', handleSearchShortcut)
  document.addEventListener('click', handleDocumentClick)
  if (publicShareToken.value) void loadPublicShare()
  else if (token.value) void loadFiles()
})

onUnmounted(() => {
  closeFilePreview()
  document.removeEventListener('click', handleDocumentClick)
  window.removeEventListener('yii-cloud:unauthorized', handleUnauthorized)
  window.removeEventListener('keydown', handleSearchShortcut)
})
</script>

<template>
  <main v-if="publicShareToken" class="auth-page share-page">
    <section class="auth-card share-card">
      <div class="brand-lockup">
        <div class="brand-icon"><span class="cloud-shape">☁</span><span class="brand-arrow">↓</span></div>
        <div><div class="brand-name">温馨云-homey</div><div class="brand-tagline">安全文件分享</div></div>
      </div>
      <div v-if="!publicDetails && !errorMessage" class="loading-state share-loading"><span class="spinner"></span><span>正在读取分享内容…</span></div>
      <template v-else-if="publicDetails">
        <div class="auth-heading share-heading">
          <p class="eyebrow">READ-ONLY SHARED {{ publicDetails.type.toUpperCase() }}</p>
          <h1>{{ publicDetails.name }}</h1>
          <p>{{ publicDetails.type === 'file' ? '此文件由温馨云-homey 用户分享给你。' : `此文件夹包含 ${publicDetails.files.length} 个文件。` }}</p>
        </div>
        <div class="shared-file-list">
          <div v-for="file in publicDetails.files" :key="file.id" class="shared-file-row">
            <span class="file-type">{{ fileGlyph(file) }}</span>
            <span class="shared-file-name">{{ file.name }}</span>
            <span class="muted-cell">{{ formatBytes(file.size) }}</span>
            <button v-if="canPreview(file)" class="secondary-button shared-download" type="button" @click="previewFile(file, true)">预览</button>
            <button class="secondary-button shared-download" type="button" @click="downloadPublicFile(file)">↓ 下载</button>
          </div>
          <p v-if="publicDetails.type === 'folder' && publicDetails.files.length === 0" class="share-empty">这个分享文件夹目前没有文件。</p>
        </div>
      </template>
      <p v-if="errorMessage" class="notice error-notice share-error" role="alert">{{ errorMessage }}</p>
      <a class="share-home-link" href="/">由温馨云-homey 私有文件云提供</a>
    </section>
  </main>

  <main v-else-if="!token" class="auth-page">
    <section class="auth-card">
      <div class="brand-lockup">
        <div class="brand-icon"><span class="cloud-shape">☁</span><span class="brand-arrow">↓</span></div>
        <div>
          <div class="brand-name">温馨云-homey</div>
          <div class="brand-tagline">文件，随身而在</div>
        </div>
      </div>

      <div class="auth-heading">
        <p class="eyebrow">YOUR PRIVATE CLOUD</p>
        <h1>{{ authMode === 'login' ? '欢迎回来' : '创建你的云空间' }}</h1>
        <p>{{ authMode === 'login' ? '登录以访问你的个人文件。' : '只需邮箱和密码，即可开始使用。' }}</p>
      </div>

      <form class="auth-form" @submit.prevent="submitAuth">
        <label for="auth-email">邮箱地址</label>
        <input id="auth-email" v-model="authEmail" type="email" autocomplete="email" placeholder="you@example.com" required maxlength="254" />
        <label for="auth-password">密码</label>
        <input id="auth-password" v-model="password" type="password" :autocomplete="authMode === 'login' ? 'current-password' : 'new-password'" placeholder="至少 8 位" required minlength="8" maxlength="72" />
        <p v-if="errorMessage" class="notice error-notice" role="alert">{{ errorMessage }}</p>
        <button class="primary-button auth-submit" type="submit" :disabled="busy">
          {{ busy ? '请稍候…' : authMode === 'login' ? '登录温馨云-homey' : '创建账号' }}
        </button>
      </form>
      <p class="auth-switch">
        {{ authMode === 'login' ? '还没有账号？' : '已经有账号？' }}
        <button class="text-button" type="button" @click="authMode = authMode === 'login' ? 'register' : 'login'; errorMessage = ''">
          {{ authMode === 'login' ? '立即注册' : '返回登录' }}
        </button>
      </p>
      <p class="auth-footnote"><span class="lock-dot">●</span> 你的文件仅保存在自己的服务器上</p>
    </section>
  </main>

  <div v-else class="app-shell">
    <aside class="sidebar">
      <a class="brand-lockup sidebar-brand" href="/" aria-label="温馨云-homey 首页">
        <div class="brand-icon small-brand-icon"><span class="cloud-shape">☁</span><span class="brand-arrow">↓</span></div>
        <div><div class="brand-name">温馨云-homey</div><div class="brand-tagline">私人文件云</div></div>
      </a>

      <div class="nav-caption">工作空间</div>
      <nav class="main-nav" aria-label="主导航">
        <button class="nav-item" :class="{ active: !showShares && !showTrash }" type="button" @click="openFilesView"><span class="nav-icon">▤</span> 我的文件 <span class="nav-count">{{ files.length }}</span></button>
        <button class="nav-item" :class="{ active: showShares }" type="button" @click="openSharesView"><span class="nav-icon">↗</span> 分享管理 <span class="nav-count">{{ shares.length }}</span></button>
        <button class="nav-item" :class="{ active: showTrash }" type="button" @click="openTrashView"><span class="nav-icon">▱</span> 回收站 <span class="nav-count">{{ trashedFiles.length }}</span></button>
      </nav>

      <div class="sidebar-spacer"></div>
      <div class="storage-card">
        <div class="storage-card-heading"><span>空间使用</span><span>{{ formatBytes(totalBytes) }}</span></div>
        <div class="storage-caption">当前文件夹内文件大小</div>
        <div class="webdav-info">WebDAV：<code>/dav/</code><br />使用邮箱和账号密码连接</div>
      </div>
      <button class="profile-button" type="button" @click="logout">
        <span class="avatar">{{ displayName.slice(0, 1).toUpperCase() }}</span>
        <span class="profile-text"><strong>{{ displayName }}</strong><small>{{ email }}</small></span>
        <span class="logout-icon" aria-hidden="true">↗</span>
      </button>
    </aside>

    <main id="files" class="content">
      <header class="topbar">
        <div class="breadcrumbs">
          <span>工作空间</span><span class="breadcrumb-divider">/</span>
          <button class="breadcrumb-button" type="button" @click="showShares ? openSharesView() : showTrash ? openTrashView() : openFilesView()">{{ showShares ? '分享管理' : showTrash ? '回收站' : '我的文件' }}</button>
          <template v-if="!showShares && !showTrash" v-for="(folder, index) in displayBreadcrumbs" :key="folder.id">
            <span class="breadcrumb-divider">/</span>
            <button v-if="index < displayBreadcrumbs.length - 1" class="breadcrumb-button" type="button" @click="navigateToFolder(index)">{{ folder.name }}</button>
            <strong v-else>{{ folder.name }}</strong>
          </template>
        </div>
        <div class="topbar-actions">
          <label class="search-box">
            <span aria-hidden="true">⌕</span>
            <input ref="searchInput" v-model="search" type="search" maxlength="200" placeholder="搜索所有文件和子目录" aria-label="搜索所有文件和子目录" />
            <kbd>⌘ K</kbd>
          </label>
          <button class="avatar top-avatar" type="button" :aria-label="`退出 ${email}`" @click="logout">{{ displayName.slice(0, 1).toUpperCase() }}</button>
        </div>
      </header>

      <section class="page-content">
        <div class="page-intro">
          <div>
            <p class="eyebrow">{{ showShares ? 'SHARED LINKS' : showTrash ? 'RECYCLE BIN' : 'PERSONAL SPACE' }}</p>
            <h1>{{ showShares ? '分享管理' : showTrash ? '回收站' : (currentFolderId ? folderPath.at(-1)?.name : '我的文件') }}<span v-if="!showShares" class="title-count">{{ showTrash ? trashedFiles.length : files.length + folders.length }}</span></h1>
            <p class="page-subtitle">{{ showShares ? '查看和撤销你创建的只读分享链接。' : showTrash ? '已删除的文件会保留在这里，直到恢复或永久删除。' : '所有重要的文件，都在一个安全的地方。' }}</p>
          </div>
          <div class="page-actions">
            <button class="secondary-button" type="button" :disabled="loadingFiles" @click="refreshCurrentView"><span class="refresh-icon">↻</span><span class="desktop-label">刷新</span></button>
            <template v-if="!showShares && !showTrash">
              <button class="secondary-button create-folder-button" type="button" :disabled="busy" @click="createFolder"><span>＋</span><span class="desktop-label">新建文件夹</span></button>
              <button class="primary-button upload-button" type="button" :disabled="busy" @click="openPicker"><span class="upload-icon">↑</span>上传文件</button>
              <input ref="uploadInput" class="visually-hidden" type="file" multiple @change="uploadSelected" />
            </template>
          </div>
        </div>

        <div class="notice-stack" aria-live="polite">
          <p v-if="errorMessage" class="notice error-notice">{{ errorMessage }}</p>
          <p v-if="successMessage" class="notice success-notice">{{ successMessage }}</p>
        </div>

        <section v-if="showShares" class="file-panel" aria-label="分享链接">
          <div class="panel-toolbar"><div class="panel-title"><span class="panel-title-icon">↗</span> 已创建的分享链接 <span class="panel-count">{{ shares.length }}</span></div></div>
          <div v-if="shares.length === 0" class="empty-state"><div class="empty-illustration"><span class="empty-cloud">↗</span></div><h2>还没有分享链接</h2><p>在文件或文件夹操作中创建只读分享。</p></div>
          <div v-else class="share-list">
            <div v-for="share in shares" :key="share.id" class="share-row">
              <span class="share-kind">{{ share.folderId ? '文件夹' : '文件' }}</span>
              <div class="share-item-info"><strong>{{ share.name }}</strong><small>{{ share.expiresAt ? `到期：${formatDate(share.expiresAt)}` : '永不过期' }}</small></div>
              <span class="share-status">只读</span>
              <button class="icon-button delete-action" type="button" :aria-label="`撤销 ${share.name} 分享`" title="撤销分享" @click="revokeShare(share)">×</button>
            </div>
          </div>
        </section>

        <section v-else-if="showTrash" class="file-panel" aria-label="回收站">
          <div class="panel-toolbar">
            <div class="panel-title"><span class="panel-title-icon">▱</span> 已删除的文件 <span class="panel-count">{{ visibleTrash.length }}</span></div>
            <div class="sort-label">最近删除 <span aria-hidden="true">⌄</span></div>
          </div>
          <div v-if="loadingFiles && trashedFiles.length === 0" class="loading-state"><span class="spinner"></span><span>正在读取回收站…</span></div>
          <div v-else-if="visibleTrash.length === 0" class="empty-state">
            <div class="empty-illustration"><span class="empty-cloud">▱</span></div>
            <h2>{{ search ? '没有找到匹配的文件' : '回收站是空的' }}</h2>
            <p>{{ search ? '试试其他文件名。' : '删除的文件会出现在这里，你可以恢复或永久删除。' }}</p>
          </div>
          <div v-else class="file-table-wrap trash-table-wrap">
            <table class="file-table trash-table">
              <thead><tr><th>名称</th><th>原位置</th><th>删除日期</th><th><span class="visually-hidden">操作</span></th></tr></thead>
              <tbody>
                <tr v-for="file in visibleTrash" :key="file.id">
                  <td><div class="file-name-cell"><span class="file-type" :class="`type-${fileGlyph(file).toLowerCase()}`">{{ fileGlyph(file) }}</span><span class="file-name" :title="file.name">{{ file.name }}</span></div></td>
                  <td class="muted-cell">{{ file.folderName ?? '我的文件' }}</td>
                  <td class="muted-cell">{{ formatDate(file.deletedAt) }}</td>
                  <td>
                    <div class="context-menu-container">
                      <button class="icon-button context-menu-trigger" type="button" :aria-label="`${file.name} 的更多操作`" aria-haspopup="menu" :aria-expanded="activeContextMenu === `file-trash-${file.id}`" @click.stop="toggleContextMenu('file', `trash-${file.id}`)">⋯</button>
                      <div v-if="activeContextMenu === `file-trash-${file.id}`" class="context-menu" role="menu" @click.stop>
                        <button class="context-menu-item" type="button" role="menuitem" :disabled="busy" @click="closeContextMenu(); restoreTrashFile(file)"><span>↶</span>恢复</button>
                        <div class="context-menu-divider"></div>
                        <button class="context-menu-item context-menu-danger" type="button" role="menuitem" :disabled="busy" @click="closeContextMenu(); permanentlyDeleteTrashFile(file)"><span>×</span>永久删除</button>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="visibleTrash.length > 0" class="mobile-file-list trash-mobile-list">
            <article v-for="file in visibleTrash" :key="file.id" class="mobile-file-card">
              <span class="file-type" :class="`type-${fileGlyph(file).toLowerCase()}`">{{ fileGlyph(file) }}</span>
              <div class="mobile-file-info"><strong :title="file.name">{{ file.name }}</strong><small>{{ file.folderName ?? '我的文件' }} · {{ formatDate(file.deletedAt) }}</small></div>
              <div class="context-menu-container">
                <button class="icon-button context-menu-trigger" type="button" :aria-label="`${file.name} 的更多操作`" aria-haspopup="menu" :aria-expanded="activeContextMenu === `file-trash-${file.id}`" @click.stop="toggleContextMenu('file', `trash-${file.id}`)">⋯</button>
                <div v-if="activeContextMenu === `file-trash-${file.id}`" class="context-menu" role="menu" @click.stop>
                  <button class="context-menu-item" type="button" role="menuitem" :disabled="busy" @click="closeContextMenu(); restoreTrashFile(file)"><span>↶</span>恢复</button>
                  <div class="context-menu-divider"></div>
                  <button class="context-menu-item context-menu-danger" type="button" role="menuitem" :disabled="busy" @click="closeContextMenu(); permanentlyDeleteTrashFile(file)"><span>×</span>永久删除</button>
                </div>
              </div>
            </article>
          </div>
        </section>

        <section v-else class="file-panel" aria-label="文件列表">
          <div class="panel-toolbar">
            <div class="panel-title"><span class="panel-title-icon">▤</span> {{ search.trim() ? '全盘搜索结果' : '当前文件夹' }} <span class="panel-count">{{ visibleFiles.length + visibleFolders.length }}</span></div>
            <div class="sort-label">{{ search.trim() ? '所有目录和子目录' : '最近更新' }} <span v-if="!search.trim()" aria-hidden="true">⌄</span></div>
          </div>
          <div v-if="loadingFiles && (files.length === 0 || search.trim())" class="loading-state">
            <span class="spinner"></span><span>{{ search.trim() ? '正在搜索所有目录和子目录…' : '正在加载文件…' }}</span>
          </div>
          <div v-else-if="!loadingFiles && visibleFiles.length === 0 && visibleFolders.length === 0" class="empty-state">
            <div class="empty-illustration"><span class="empty-cloud">☁</span><span class="empty-plus">＋</span></div>
            <h2>{{ search.trim() ? '没有找到匹配的文件' : '你的云空间准备好了' }}</h2>
            <p>{{ search.trim() ? '已搜索所有目录和子目录，请尝试其他文件名。' : '上传文件或新建文件夹，整理你的云空间。' }}</p>
            <button v-if="!search" class="secondary-button empty-upload" type="button" :disabled="busy" @click="openPicker"><span>↑</span> 选择文件</button>
          </div>

          <template v-if="!loadingFiles && visibleFolders.length > 0">
            <div class="folder-list">
              <div v-for="folder in visibleFolders" :key="folder.id" class="folder-row">
                <button class="folder-open" type="button" @click="openFolder(folder)"><span class="folder-icon">▰</span><strong>{{ folder.name }}</strong><span class="folder-arrow">›</span></button>
                <div class="context-menu-container">
                  <button
                    class="icon-button context-menu-trigger"
                    type="button"
                    :aria-label="`${folder.name} 的更多操作`"
                    aria-haspopup="menu"
                    :aria-expanded="activeContextMenu === `folder-${folder.id}`"
                    @click.stop="toggleContextMenu('folder', folder.id)"
                  >⋯</button>
                  <div v-if="activeContextMenu === `folder-${folder.id}`" class="context-menu" role="menu" @click.stop>
                    <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); openFolder(folder)"><span>↗</span>打开</button>
                    <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); createShare(folder, 'folder')"><span>↗</span>分享</button>
                    <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); moveFolder(folder)"><span>↪</span>移动</button>
                    <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); renameFolder(folder)"><span>✎</span>重命名</button>
                    <div class="context-menu-divider"></div>
                    <button class="context-menu-item context-menu-danger" type="button" role="menuitem" @click="closeContextMenu(); deleteFolder(folder)"><span>×</span>删除</button>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <template v-if="!loadingFiles && visibleFiles.length > 0">
            <div class="file-table-wrap">
              <table class="file-table">
                <thead><tr><th>名称</th><th>文件大小</th><th>修改日期</th><th><span class="visually-hidden">操作</span></th></tr></thead>
                <tbody>
                  <tr v-for="file in visibleFiles" :key="file.id">
                    <td><div class="file-name-cell"><span class="file-type" :class="`type-${fileGlyph(file).toLowerCase()}`">{{ fileGlyph(file) }}</span><span class="search-file-name"><button v-if="canPreview(file)" class="file-name file-name-preview" type="button" :title="`预览 ${file.name}`" @click="previewFile(file)">{{ file.name }}</button><span v-else class="file-name" :title="file.name">{{ file.name }}</span><small v-if="search.trim()" class="search-file-path">{{ file.folderPath }}</small></span></div></td>
                    <td class="muted-cell">{{ formatBytes(file.size) }}</td>
                    <td class="muted-cell">{{ formatDate(file.createdAt) }}</td>
                    <td>
                      <div class="context-menu-container">
                        <button
                          class="icon-button context-menu-trigger"
                          type="button"
                          :aria-label="`${file.name} 的更多操作`"
                          aria-haspopup="menu"
                          :aria-expanded="activeContextMenu === `file-${file.id}`"
                          @click.stop="toggleContextMenu('file', file.id)"
                        >⋯</button>
                        <div v-if="activeContextMenu === `file-${file.id}`" class="context-menu" role="menu" @click.stop>
                          <button v-if="canPreview(file)" class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); previewFile(file)"><span>◉</span>预览</button>
                          <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); createShare(file, 'file')"><span>↗</span>分享</button>
                          <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); moveFile(file)"><span>↪</span>移动</button>
                          <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); downloadFile(file)"><span>↓</span>下载</button>
                          <div class="context-menu-divider"></div>
                          <button class="context-menu-item context-menu-danger" type="button" role="menuitem" @click="closeContextMenu(); deleteFile(file)"><span>×</span>删除</button>
                        </div>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="mobile-file-list">
              <article v-for="file in visibleFiles" :key="file.id" class="mobile-file-card">
                <span class="file-type" :class="`type-${fileGlyph(file).toLowerCase()}`">{{ fileGlyph(file) }}</span>
              <div class="mobile-file-info"><button v-if="canPreview(file)" class="file-name-preview" type="button" :title="`预览 ${file.name}`" @click="previewFile(file)">{{ file.name }}</button><strong v-else :title="file.name">{{ file.name }}</strong><small v-if="search.trim()" class="search-file-path">{{ file.folderPath }}</small><small>{{ formatBytes(file.size) }} <span>·</span> {{ formatDate(file.createdAt) }}</small></div>
                <div class="context-menu-container">
                  <button
                    class="icon-button context-menu-trigger"
                    type="button"
                    :aria-label="`${file.name} 的更多操作`"
                    aria-haspopup="menu"
                    :aria-expanded="activeContextMenu === `file-${file.id}`"
                    @click.stop="toggleContextMenu('file', file.id)"
                  >⋯</button>
                  <div v-if="activeContextMenu === `file-${file.id}`" class="context-menu" role="menu" @click.stop>
                    <button v-if="canPreview(file)" class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); previewFile(file)"><span>◉</span>预览</button>
                    <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); createShare(file, 'file')"><span>↗</span>分享</button>
                    <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); moveFile(file)"><span>↪</span>移动</button>
                    <button class="context-menu-item" type="button" role="menuitem" @click="closeContextMenu(); downloadFile(file)"><span>↓</span>下载</button>
                    <div class="context-menu-divider"></div>
                    <button class="context-menu-item context-menu-danger" type="button" role="menuitem" @click="closeContextMenu(); deleteFile(file)"><span>×</span>删除</button>
                  </div>
                </div>
              </article>
            </div>
          </template>
        </section>

        <footer class="page-footer"><span><span class="secure-dot"></span> 安全连接 · WebDAV 地址 {{ webdavUrl }}</span><span>温馨云-homey 0.1</span></footer>
      </section>
    </main>
  </div>

  <div
    v-if="activeDialog"
    class="dialog-backdrop"
    @click.self="closeActionDialog()"
    @keydown.esc.prevent="closeActionDialog()"
  >
    <form
      class="action-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="action-dialog-title"
      @submit.prevent="submitActionDialog"
    >
      <h2 id="action-dialog-title">{{ activeDialog.title }}</h2>
      <p v-if="activeDialog.description" class="dialog-description">{{ activeDialog.description }}</p>
      <label v-if="activeDialog.inputType === 'text' || activeDialog.inputType === 'datetime-local' || activeDialog.inputType === 'link'" class="dialog-field">
        <span>{{ activeDialog.inputLabel }}</span>
        <input
          v-model="dialogValue"
          :type="activeDialog.inputType === 'text' ? 'text' : activeDialog.inputType === 'datetime-local' ? 'datetime-local' : 'url'"
          :readonly="activeDialog.inputType === 'link'"
          :required="activeDialog.inputType === 'text'"
          maxlength="255"
          autofocus
        />
      </label>
      <label v-else-if="activeDialog.inputType === 'select'" class="dialog-field">
        <span>{{ activeDialog.inputLabel }}</span>
        <select v-model="dialogValue" autofocus>
          <option v-for="option in activeDialog.options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
      </label>
      <p v-if="dialogError" class="dialog-error" role="alert">{{ dialogError }}</p>
      <p v-if="dialogFeedback" class="dialog-feedback" role="status">{{ dialogFeedback }}</p>
      <div class="dialog-actions">
        <button v-if="activeDialog.inputType !== 'link'" class="secondary-button" type="button" @click="closeActionDialog()">取消</button>
        <button v-if="activeDialog.inputType === 'link'" class="secondary-button" type="button" @click="copyShareLink">复制链接</button>
        <button
          class="primary-button"
          :class="{ 'dialog-danger': activeDialog.destructive }"
          type="submit"
        >{{ activeDialog.submitLabel ?? '确定' }}</button>
      </div>
    </form>
  </div>

  <div
    v-if="filePreview"
    class="preview-backdrop"
    role="presentation"
    @click.self="closeFilePreview"
    @keydown.esc.prevent="closeFilePreview"
  >
    <section class="preview-dialog" role="dialog" aria-modal="true" aria-labelledby="preview-title">
      <header class="preview-toolbar">
        <h2 id="preview-title" :title="filePreview.file.name">{{ filePreview.file.name }}</h2>
        <div class="preview-toolbar-actions">
          <button class="secondary-button" type="button" :disabled="busy" @click="downloadPreviewFile">↓ 下载</button>
          <button class="icon-button preview-close" type="button" aria-label="关闭预览" @click="closeFilePreview">×</button>
        </div>
      </header>
      <div class="preview-content">
        <div v-if="filePreview.loading" class="loading-state"><span class="spinner"></span><span>正在加载预览…</span></div>
        <p v-else-if="filePreview.error" class="notice error-notice preview-error" role="alert">{{ filePreview.error }}</p>
        <img v-else-if="filePreview.kind === 'image'" class="image-preview" :src="filePreview.url" :alt="filePreview.file.name" />
        <iframe v-else-if="filePreview.kind === 'pdf'" class="pdf-preview" :src="filePreview.url" :title="filePreview.file.name"></iframe>
        <audio v-else-if="filePreview.kind === 'audio'" class="media-preview" :src="filePreview.url" controls preload="metadata">当前浏览器不支持播放此音频。</audio>
        <video v-else-if="filePreview.kind === 'video'" class="video-preview" :src="filePreview.url" controls playsinline preload="metadata">当前浏览器不支持播放此视频。</video>
        <pre v-else-if="filePreview.kind === 'text'" class="text-preview">{{ filePreview.text }}</pre>
        <div v-else-if="filePreview.kind === 'docx'" ref="docxPreviewContent" class="docx-preview-content"></div>
      </div>
    </section>
  </div>
</template>
