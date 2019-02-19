<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
//Route::resource('users', 'API\ApiController');
Route::middleware('auth:api')->group( function () {
	//Route::resource('users', 'API\ApiController');
	Route::get('/getDepot', 'API\ApiController@getDepot');
	Route::get('/checkKey', 'API\ApiController@checkKey');
	Route::get('/getRoles', 'API\ApiController@getRoles');
	Route::get('/getDealersQuickList', 'API\ApiController@getDealersQuickList');
	Route::get('/getProductCategories', 'API\ApiController@getProductCategories');
	Route::post('/syncPull', 'API\ApiController@syncPull');
	Route::post('/syncPullCount', 'API\ApiController@syncPullCount');

	Route::post('/syncPush', 'API\ApiController@syncPush');
	Route::post('/syncDelete', 'API\ApiController@syncDelete');
	Route::post('/getLogin', 'API\ApiController@getLogin');
	Route::post('/setLogin', 'API\ApiController@setLogin');

	Route::resource('staff', 'API\StaffController');

	//server firebase
	Route::post('/accessByEmail', 'API\ApiController@accessByEmail');
	Route::post('/updateFCMToken', 'API\ApiController@updateFCMToken');

	/* Stats */
	//Top 10 Dealers
	Route::post('/topTenDealers', 'API\ApiController@topTenDealers');
	//Top 10 Depot
	Route::post('/topTenDepot', 'API\ApiController@topTenDepot');
	//
	Route::post('/depotTotalClients', 'API\ApiController@depotTotalClients');
	Route::post('/depotTotalMachines', 'API\ApiController@depotTotalMachines');
	Route::post('/dealerFullInfo', 'API\ApiController@dealerFullInfo');
	Route::post('/depotClientsMonthInc', 'API\ApiController@depotClientsMonthInc');
	//machines nearby
	Route::post('/machinesNearby', 'API\ApiController@machinesNearby');

	/* Depot Dashboard*/
	Route::post('/depotDashboardDealers', 'API\ApiController@depotDashboardDealers');

	//test
	Route::post('/test', 'API\ApiController@test');

	//Version Check
	Route::get('/dealerVersion', 'API\ApiController@dealerVersion');
	
});


