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
//fixes
Route::get('/fixNoLocations', 'API\ApiController@fixNoLocations');
//End of fixes
Route::post('/serverLogin', 'API\ApiController@serverLogin');
Route::post('/resetPassword', 'API\ApiController@resetPassword');
Route::post('/paypalPay2', 'API\ApiController@paypalPay2');
Route::get('/oneSignal', 'API\ApiController@oneSignal');

//Route::resource('users', 'API\ApiController');
Route::middleware('auth:api')->group( function () {
	Route::post('/getVerifiedMachineStatus', 'API\ApiController@getVerifiedMachineStatus');
	Route::post('/setMachineDealer', 'API\ApiController@setMachineDealer');
	Route::post('/getTopDepotVerifiedMachines', 'API\ApiController@getTopDepotVerifiedMachines');
	Route::post('/updateStaffName', 'API\ApiController@updateStaffName');
	Route::post('/deleteMachine', 'API\ApiController@deleteMachine');
	Route::post('/setMachineVerification', 'API\ApiController@setMachineVerification');
	Route::post('/dealerMachinesSchedule', 'API\ApiController@dealerMachinesSchedule');
	Route::post('/setMachineDelivery', 'API\ApiController@setMachineDelivery');
	Route::post('/dealerMachines', 'API\ApiController@dealerMachines');
	Route::post('/productivityView', 'API\ApiController@productivityView');
	Route::post('/getClientProfile', 'API\ApiController@getClientProfile');
	Route::match(['get', 'post'], '/clientFilter', 'API\ApiController@clientFilter');
	Route::post('/getReceipt', 'API\ApiController@getReceipt');
	Route::match(['get', 'post'], '/callsheetFilter', 'API\ApiController@callsheetFilter');
	Route::post('/getTopLocations', 'API\ApiController@getTopLocations');
	Route::post('/getTypeofMachinesCount', 'API\ApiController@getTypeofMachinesCount');
	Route::post('/getMachinesSummary', 'API\ApiController@getMachinesSummary');
	Route::post('/getDashboardFirstBatchTop', 'API\ApiController@getDashboardFirstBatchTop');
	//Route::resource('users', 'API\ApiController');
	Route::get('/getDepot', 'API\ApiController@getDepot');
	Route::get('/checkKey', 'API\ApiController@checkKey');
	Route::get('/getRoles', 'API\ApiController@getRoles');
	Route::match(['get', 'post'], '/getDealersQuickList', 'API\ApiController@getDealersQuickList');
	Route::match(['get', 'post'], '/machineFilter', 'API\ApiController@machineFilter');
	Route::post('/getProvinceList', 'API\ApiController@getProvinceList');
	Route::post('/getMunicipalList', 'API\ApiController@getMunicipalList');
	Route::post('/getBrgyList', 'API\ApiController@getBrgyList');
	Route::get('/getProductCategories', 'API\ApiController@getProductCategories');
	Route::post('/syncPull', 'API\ApiController@syncPull');
	Route::post('/syncPullDealers', 'API\ApiController@syncPullDealers');
	Route::post('/syncPullCount', 'API\ApiController@syncPullCount');
	Route::post('/transferMachines', 'API\ApiController@transferMachines');
	Route::get('/checkMachineTransfers', 'API\ApiController@checkMachineTransfers');
	Route::get('/goMachineTransfers', 'API\ApiController@goMachineTransfers');
	Route::get('/completeMachineTransfers', 'API\ApiController@completeMachineTransfers');
	Route::get('/getMachines', 'API\ApiController@getMachines');
	Route::get('/paymentStatus', 'API\ApiController@paymentStatus');
	Route::get('/clientDetails', 'API\ApiController@clientDetails');
	Route::get('/pullClientDetails', 'API\ApiController@pullClientDetails');
	Route::post('/machinesOnMap', 'API\ApiController@machinesOnMap');
	Route::post('/getMachineProfile', 'API\ApiController@getMachineProfile');
	Route::post('/getCallsheets', 'API\ApiController@getCallsheets');

	Route::post('/syncPush', 'API\ApiController@syncPush');
	Route::post('/syncPushDealers', 'API\ApiController@syncPushDealers');
	Route::post('/syncDelete', 'API\ApiController@syncDelete');
	Route::post('/getLogin', 'API\ApiController@getLogin');
	Route::post('/setLogin', 'API\ApiController@setLogin');
	Route::post('/postProxy', 'API\ApiController@postProxy');

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
	

	//Version Check
	Route::get('/dealerVersion', 'API\ApiController@dealerVersion');
	
	/* People Help People*/
	Route::post('/nexmoOTP', 'API\PHPController@nexmoOTP');
	Route::post('/nexmoVerifyOTP', 'API\PHPController@nexmoVerifyOTP');
	Route::get('/PHPcategories', 'API\PHPController@PHPcategories');
	Route::get('/PHPsubcategories', 'API\PHPController@PHPsubcategories');
	Route::get('/PHPProvinceList', 'API\PHPController@PHPProvinceList');
	Route::get('/PHPMunicipalList', 'API\PHPController@PHPMunicipalList');
	Route::get('/PHPBrgyList', 'API\PHPController@PHPBrgyList');
	Route::get('/getLoc', 'API\PHPController@getLoc');
	Route::post('/phpContributionSubmit', 'API\PHPController@phpContributionSubmit');

	/* E-Tinda */
	Route::post('/createProductCategory', 'API\EtindaController@createProductCategory');
	Route::get('/getProductCategory', 'API\EtindaController@getProductCategory');
	Route::get('/getMainProductCategory', 'API\EtindaController@getMainProductCategory');
	Route::get('/getCategories', 'API\EtindaController@getCategories');
	Route::get('/getSpecKeys', 'API\EtindaController@getSpecKeys');
	Route::get('/getProductTags', 'API\EtindaController@getProductTags');
	Route::get('/getProducts', 'API\EtindaController@getProducts');
	Route::get('/checkBarcode', 'API\EtindaController@checkBarcode');
	Route::get('/searchProducts', 'API\EtindaController@searchProducts');
	Route::get('/getProductDetails', 'API\EtindaController@getProductDetails');
	Route::get('/pabileBrgyList', 'API\EtindaController@pabileBrgyList');
	Route::get('/searchClients', 'API\EtindaController@searchClients');
	Route::get('/deliveries', 'API\EtindaController@deliveries');
	Route::get('/getOrderItems', 'API\EtindaController@getOrderItems');
	Route::get('/getRiders', 'API\EtindaController@getRiders');
	Route::get('/getPurchases', 'API\EtindaController@getPurchases');
	Route::get('/getOrdersInPurchases', 'API\EtindaController@getOrdersInPurchases');
	Route::get('/orderInfo', 'API\EtindaController@orderInfo');
	Route::get('/getCategoriesById', 'API\EtindaController@getCategoriesById');
	Route::get('/getFbOrders', 'API\EtindaController@getFbOrders');
	

	Route::post('/createSpecKeys', 'API\EtindaController@createSpecKeys');
	Route::post('/createNewProduct', 'API\EtindaController@createNewProduct');
	Route::post('/purchase', 'API\EtindaController@purchase');
	Route::post('/addClient', 'API\EtindaController@addClient');
	Route::post('/submitOrder', 'API\EtindaController@submitOrder');
	Route::post('/voidOrder', 'API\EtindaController@voidOrder');
	Route::post('/processOrder', 'API\EtindaController@processOrder');
	Route::post('/completeOrder', 'API\EtindaController@completeOrder');
	Route::post('/setRider', 'API\EtindaController@setRider');
	Route::post('/deliveredOrder', 'API\EtindaController@deliveredOrder');
	Route::post('/removePurchases', 'API\EtindaController@removePurchases');
});

Route::get('/ETinda', 'API\PHPController@ETinda');
Route::get('/updateMobilePrefix', 'API\EtindaController@updateMobilePrefix');
Route::get('/getProductAvgCost', 'API\EtindaController@getProductAvgCost');

//E-Tinda Bot
Route::get('/botMainProductCategories', 'API\EtindaController@botMainProductCategories');
Route::post('/botWelcome', 'API\BotController@botWelcome');
Route::get('/getBotMainProductCategory', 'API\BotController@getBotMainProductCategory');
Route::get('/getBotCategoriesById', 'API\BotController@getBotCategoriesById');
Route::post('/botGetToken', 'API\BotController@botGetToken');
Route::post('/fbOrder', 'API\BotController@fbOrder');
Route::get('/getTempOrders', 'API\BotController@getTempOrders');
Route::post('/deleteTempOrders', 'API\BotController@deleteTempOrders');
Route::get('/getBrgyInfo', 'API\BotController@getBrgyInfo');
Route::post('/botSetLocation', 'API\BotController@botSetLocation');
Route::post('/botSetAddress', 'API\BotController@botSetAddress');
Route::post('/botSummary', 'API\BotController@botSummary');
Route::post('/botAddKeyword', 'API\BotController@botAddKeyword');
Route::post('/botItemSelected', 'API\BotController@botItemSelected');
Route::post('/botSearchProduct', 'API\BotController@botSearchProduct');


Route::get('/botTimeNow', 'API\BotController@botTimeNow');
Route::get('/botAddtoCart', 'API\BotController@botAddtoCart');
