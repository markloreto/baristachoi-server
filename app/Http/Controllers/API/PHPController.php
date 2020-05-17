<?php

namespace App\Http\Controllers\API;

use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Support\Facades\Hash;
use DB;
use Validator;
use Intervention\Image\ImageManagerStatic as Image;
use App\Services\PayUService\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Stream\Stream;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Collection;
use OneSignal;

use Rap2hpoutre\FastExcel\FastExcel;

use App\Exports\MachinesExport;
use App\Exports\CallsheetsExport;
use App\Exports\ClientsExport;
use Maatwebsite\Excel\Facades\Excel;
//
class PHPController extends BaseController
{
    public function nexmoOTP(Request $request){
        $data = $request->all();
        $phoneNumber = $data["phoneNumber"];
        $basic  = new \Nexmo\Client\Credentials\Basic('9662548f', 'fsP0efbavPlPtfY0');
        $client = new \Nexmo\Client($basic);

        $verification = $client->verify()->start([ 
            'number' => '63' . $phoneNumber,
            'brand'  => 'Vonage',
             'code_length'  => '6']);

        return $this->sendResponse(array("verification" => $verification->getRequestId()), 'Depot retrieved successfully.');
    }

    public function nexmoVerifyOTP(Request $request){
        $data = $request->all();
        $requestId = $data["phoneNumber"];
        $code = $data["code"];

        $verification = new \Nexmo\Verify\Verification($requestId);
        $result = $client->verify()->check($verification, $code);

        return $this->sendResponse($result, 'Depot retrieved successfully.');
    }
}
