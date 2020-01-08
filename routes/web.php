<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/', 'TextractController@dashboard')->name('dashboard');
Route::get('/refresh', 'TextractController@refreshPdf')->name('refresh');
Route::get('/analize', 'TextractController@startDetectionByDashBoard')->name('analize');

Route::get('/textract/{id}', 'TextractController@getDetection');
Route::post('/textract/aws/sns', 'TextractController@getDetectionBySNS');

Route::get('/sns', 'TextractController@snsSbc');
