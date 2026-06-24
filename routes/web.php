<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/rag', 'Rag')->name('rag');
Route::inertia('/chat', 'Chat')->name('chat');
