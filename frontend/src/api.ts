export interface CloudFile {
  id: string
  name: string
  size: number
  mimeType: string
  createdAt?: string
  folderId?: string | null
  folderPath?: string
}

export interface TrashedFile extends CloudFile {
  deletedAt: string
  folderName: string | null
}

export interface CloudFolder {
  id: string
  name: string
  parentId: string | null
  createdAt?: string
}

export interface CloudShare {
  id: string
  fileId: string | null
  folderId: string | null
  name: string
  expiresAt: string | null
  createdAt: string
}

export interface SharedDetails {
  type: 'file' | 'folder'
  name: string
  size?: number
  mimeType?: string
  files: CloudFile[]
}

interface ApiEnvelope<T> {
  status: 'success' | 'failed'
  data?: T
  error_message?: string
}

export interface Account {
  userId: string
  email: string
  token: string
}

const API_ROOT = import.meta.env.VITE_API_ROOT ?? ''

async function request<T>(path: string, options: RequestInit = {}, token?: string): Promise<T> {
  const headers = new Headers(options.headers)
  if (token) headers.set('Authorization', `Bearer ${token}`)

  const response = await fetch(`${API_ROOT}${path}`, { ...options, headers })
  const contentType = response.headers.get('Content-Type') ?? ''

  if (!response.ok) {
    let message = `请求失败 (${response.status})`
    if (contentType.includes('application/json')) {
      const payload = (await response.json()) as ApiEnvelope<unknown>
      message = payload.error_message ?? message
    }
    if (response.status === 401) {
      window.dispatchEvent(new Event('yii-cloud:unauthorized'))
    }
    throw new Error(message)
  }

  if (!contentType.includes('application/json')) {
    throw new Error('服务器返回了无法识别的数据格式。')
  }
  const payload = (await response.json()) as ApiEnvelope<T>
  if (payload.status !== 'success' || payload.data === undefined) {
    throw new Error(payload.error_message ?? '服务器未返回预期数据。')
  }
  return payload.data
}

async function previewBlob(path: string, token?: string): Promise<Blob> {
  const headers = new Headers()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  const response = await fetch(`${API_ROOT}${path}`, { headers })
  if (!response.ok) {
    if (response.status === 401) window.dispatchEvent(new Event('yii-cloud:unauthorized'))
    if (response.status === 415) throw new Error('此文件类型暂不支持在线预览，请下载后查看。')
    throw new Error(response.status === 404 ? '文件不存在或已被删除。' : `预览失败 (${response.status})`)
  }

  return response.blob()
}

export const api = {
  authenticate(email: string, password: string, createAccount: boolean) {
    return request<Account>(createAccount ? '/api/auth/register' : '/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    })
  },

  listFiles(token: string, folderId: string | null, search = '') {
    const params = new URLSearchParams()
    if (search) params.set('search', search)
    else if (folderId) params.set('folderId', folderId)
    const query = params.toString() ? `?${params.toString()}` : ''
    return request<CloudFile[]>(`/api/files${query}`, {}, token)
  },

  listTrash(token: string) {
    return request<TrashedFile[]>('/api/trash', {}, token)
  },

  restoreTrashFile(token: string, file: TrashedFile) {
    return request<{ restored: boolean }>(`/api/trash/${encodeURIComponent(file.id)}/restore`, {
      method: 'POST',
    }, token)
  },

  permanentlyDeleteTrashFile(token: string, file: TrashedFile) {
    return request<{ deleted: boolean }>(`/api/trash/${encodeURIComponent(file.id)}`, {
      method: 'DELETE',
    }, token)
  },

  listFolders(token: string, parentId: string | null) {
    const query = parentId ? `?parentId=${encodeURIComponent(parentId)}` : ''
    return request<CloudFolder[]>(`/api/folders${query}`, {}, token)
  },

  createFolder(token: string, name: string, parentId: string | null) {
    return request<CloudFolder>('/api/folders', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, parentId }),
    }, token)
  },

  renameFolder(token: string, folder: CloudFolder, name: string) {
    return request<CloudFolder>(`/api/folders/${encodeURIComponent(folder.id)}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name }),
    }, token)
  },

  moveFolder(token: string, folder: CloudFolder, parentId: string | null) {
    return request<CloudFolder>(`/api/folders/${encodeURIComponent(folder.id)}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ parentId }),
    }, token)
  },

  deleteFolder(token: string, folder: CloudFolder) {
    return request<{ deleted: boolean }>(`/api/folders/${encodeURIComponent(folder.id)}`, {
      method: 'DELETE',
    }, token)
  },

  upload(token: string, file: File, folderId: string | null) {
    const body = new FormData()
    body.set('file', file)
    if (folderId) body.set('folderId', folderId)
    return request<CloudFile>('/api/files', { method: 'POST', body }, token)
  },

  async download(token: string, file: CloudFile) {
    const response = await fetch(`${API_ROOT}/api/files/${encodeURIComponent(file.id)}`, {
      headers: { Authorization: `Bearer ${token}` },
    })
    if (!response.ok) {
      if (response.status === 401) window.dispatchEvent(new Event('yii-cloud:unauthorized'))
      throw new Error(response.status === 404 ? '文件不存在或已被删除。' : `下载失败 (${response.status})`)
    }
    const blob = await response.blob()
    const objectUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = file.name
    document.body.append(anchor)
    anchor.click()
    anchor.remove()
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
  },

  preview(token: string, file: CloudFile) {
    return previewBlob(`/api/files/${encodeURIComponent(file.id)}?preview=1`, token)
  },

  deleteFile(token: string, file: CloudFile) {
    return request<{ deleted: boolean }>(`/api/files/${encodeURIComponent(file.id)}`, {
      method: 'DELETE',
    }, token)
  },

  moveFile(token: string, file: CloudFile, folderId: string | null) {
    return request<{ moved: boolean }>(`/api/files/${encodeURIComponent(file.id)}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ folderId }),
    }, token)
  },

  createFileShare(token: string, file: CloudFile, expiresAt: string | null) {
    return request<{ id: string; token: string; expiresAt: string | null }>(
      `/api/files/${encodeURIComponent(file.id)}/shares`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ expiresAt }),
      },
      token,
    )
  },

  createFolderShare(token: string, folder: CloudFolder, expiresAt: string | null) {
    return request<{ id: string; token: string; expiresAt: string | null }>(
      `/api/folders/${encodeURIComponent(folder.id)}/shares`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ expiresAt }),
      },
      token,
    )
  },

  listShares(token: string) {
    return request<CloudShare[]>('/api/shares', {}, token)
  },

  deleteShare(token: string, share: CloudShare) {
    return request<{ deleted: boolean }>(`/api/shares/${encodeURIComponent(share.id)}`, {
      method: 'DELETE',
    }, token)
  },

  publicShare(token: string) {
    return request<SharedDetails>(`/api/shared/${encodeURIComponent(token)}`)
  },

  async downloadShared(token: string, file: CloudFile) {
    const response = await fetch(`${API_ROOT}/api/shared/${encodeURIComponent(token)}/files/${encodeURIComponent(file.id)}`)
    if (!response.ok) {
      throw new Error(response.status === 404 ? '分享文件不存在或链接已过期。' : `下载失败 (${response.status})`)
    }
    const blob = await response.blob()
    const objectUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = file.name
    document.body.append(anchor)
    anchor.click()
    anchor.remove()
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
  },

  previewShared(token: string, file: CloudFile) {
    return previewBlob(
      `/api/shared/${encodeURIComponent(token)}/files/${encodeURIComponent(file.id)}?preview=1`,
    )
  },
}
