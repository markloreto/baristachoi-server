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


Route::group(['prefix' => 'admin'], function () {
    Voyager::routes();
});

//Route::get('foo', 'API\ApiController@importOldServer');

Route::get('chrono/abc/{depot_id}/{year}/{month}', 'BasicController@abc');
Route::get('generatePaymentCode/{days}', 'BasicController@generatePaymentCode');
Route::get('attachmentView/{id}', 'BasicController@attachmentView');
Route::get('profilePhoto/{id}', 'BasicController@profilePhoto');
