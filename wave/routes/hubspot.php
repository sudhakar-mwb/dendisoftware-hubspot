<?php
use Illuminate\Support\Facades\Route;
// ADD
Route::post('/contact', '\Wave\Http\Controllers\HubSpot\HubspotController@contactActivities');
Route::post('/orderCreated', '\Wave\Http\Controllers\HubSpot\HubspotController@dendiOrderCreate');
Route::post('/orderUpdated', '\Wave\Http\Controllers\HubSpot\HubspotController@dendiOrderUpdated');