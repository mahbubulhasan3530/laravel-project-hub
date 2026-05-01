<?php

use Illuminate\Support\Facades\Route;

// Main route
Route::get('/', function () {
    return "Laravel Kubernetes Deployment Test";
});

// Health check endpoint - returns HTTP 200
Route::get('/health', function () {
    return response('Health', 200)
        ->header('Content-Type', 'text/plain');
});