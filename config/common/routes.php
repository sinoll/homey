<?php

declare(strict_types=1);

use App\Api;
use Yiisoft\Router\Route;

/**
 * @var array $params
 */

return [
    Route::get('/')->action(Api\IndexAction::class)->name('app/index'),
    Route::post('/api/auth/register')->action(Api\Auth\RegisterAction::class)->name('auth/register'),
    Route::post('/api/auth/login')->action(Api\Auth\LoginAction::class)->name('auth/login'),
    Route::get('/api/files')->action(Api\Files\ListAction::class)->name('files/list'),
    Route::post('/api/files')->action(Api\Files\UploadAction::class)->name('files/upload'),
    Route::get('/api/files/{id}')->action(Api\Files\DownloadAction::class)->name('files/download'),
    Route::patch('/api/files/{id}')->action(Api\Files\MoveAction::class)->name('files/move'),
    Route::delete('/api/files/{id}')->action(Api\Files\DeleteAction::class)->name('files/delete'),
    Route::get('/api/trash')->action(Api\Trash\ListAction::class)->name('trash/list'),
    Route::post('/api/trash/{id}/restore')->action(Api\Trash\RestoreAction::class)->name('trash/restore'),
    Route::delete('/api/trash/{id}')->action(Api\Trash\DeleteAction::class)->name('trash/delete'),
    Route::get('/api/folders')->action(Api\Folders\ListAction::class)->name('folders/list'),
    Route::post('/api/folders')->action(Api\Folders\CreateAction::class)->name('folders/create'),
    Route::patch('/api/folders/{id}')->action(Api\Folders\UpdateAction::class)->name('folders/update'),
    Route::delete('/api/folders/{id}')->action(Api\Folders\DeleteAction::class)->name('folders/delete'),
    Route::post('/api/files/{id}/shares')->action(Api\Shares\CreateFileAction::class)->name('shares/create-file'),
    Route::post('/api/folders/{id}/shares')->action(Api\Shares\CreateFolderAction::class)->name('shares/create-folder'),
    Route::get('/api/shares')->action(Api\Shares\ListAction::class)->name('shares/list'),
    Route::delete('/api/shares/{id}')->action(Api\Shares\DeleteAction::class)->name('shares/delete'),
    Route::get('/api/shared/{token}')->action(Api\Shares\PublicDetailsAction::class)->name('shares/public-details'),
    Route::get('/api/shared/{token}/files/{fileId}')->action(Api\Shares\PublicDownloadAction::class)->name('shares/public-download'),
];
