<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DiagnosticController;
use App\Http\Controllers\RagController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [DiagnosticController::class, 'testConnection']);

// Assignment 1 — RAG AI Agent
Route::post('/rag/upload', [RagController::class, 'upload'])->name('rag.upload');
Route::post('/rag/query',  [RagController::class, 'query'])->name('rag.query');

// Assignment 2 — Live Context Switching Chatbot
Route::post('/chat/message',        [ChatController::class, 'message'])->name('chat.message');
Route::post('/chat/context',        [ChatController::class, 'switchContext'])->name('chat.context');
Route::delete('/chat/session/{id}', [ChatController::class, 'clearSession'])->name('chat.session.clear');
