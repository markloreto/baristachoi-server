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
class ApiController extends BaseController
{
    public $months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    public function getDepot(){

        $depot = DB::table('depots AS d')->select('d.id', 'd.name', 'd.lat', 'd.lng', 'l.name_1 AS province', 'l.name_2 AS municipal', 'l.name_3 AS brgy')
        ->join('locations AS l', 'd.location_id', '=', 'l.id')
        ->get();

        return $this->sendResponse($depot->toArray(), 'Depot retrieved successfully.');
    }

    public function test(){
        $basic  = new \Nexmo\Client\Credentials\Basic('9662548f', 'fsP0efbavPlPtfY0');
        $client = new \Nexmo\Client($basic);

        $verification = $client->verify()->start([ 
            'number' => '639173242410',
            'brand'  => 'Vonage',
             'code_length'  => '6']);

        return $this->sendResponse(array("verification" => $verification->getRequestId()), 'Depot retrieved successfully.');
    }

    public function getVerifiedMachineStatus(Request $request){
        $data = $request->all();
        $expDate = Carbon::now()->addDays(30);
        $active = DB::table("machines AS m")->join('depots AS d', 'd.id', '=', 'm.depot_id')->select(DB::raw('d.id AS depot_id, d.name AS `Depot Name`, COUNT(*) AS `active`'))->where(function ($query) {
            $query->whereNotNull('m.client_id');
        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) < 31 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0 AND m.verified = 1')->groupBy(DB::raw('m.depot_id'))->get();
        $inactive = DB::table("machines AS m")->join('depots AS d', 'd.id', '=', 'm.depot_id')->select(DB::raw('d.id AS depot_id, d.name AS `Depot Name`, COUNT(*) AS `inactive`'))->where(function ($query) {
            $query->whereNotNull('m.client_id');
        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) > 30 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0 AND m.verified = 1')->groupBy(DB::raw('m.depot_id'))->get();

        return $this->sendResponse(array("active" => $active, "inactive" => $inactive), 'getVerifiedMachineStatus');
    }

    public function setMachineDealer(Request $request){
        $data = $request->all();
        $ids = $data["ids"];
        $dealerId = intval($data["dealerId"]);

        $records = DB::table('machines')->select("client_id")->whereIn('id', $ids)->whereNotNull('client_id')->get();
        foreach($records AS $record){
            DB::table('clients')->where('id', $record->client_id)->update(['staff_id' => $dealerId]);
        }

        DB::table('machines')->whereIn('id', $ids)->update(['staff_id' => $dealerId]);

        OneSignal::sendNotificationUsingTags(
            count($ids) . " machines transferred to you",
            array(
                ["field" => "tag", "key" => "userId", "relation" => "=", "value" => $dealerId]
            ),
            $url = null,
            $data = null,
            $buttons = null,
            $schedule = null
        );

        return $this->sendResponse("", 'setMachineDealer retrieved successfully.');
    }

    public function getTopDepotVerifiedMachines(Request $request){
        $data = $request->all();

        $records = DB::table('machines AS m')
        ->select(DB::raw('d.id AS depot_id, d.name AS `depot`, COUNT(m.verified) AS `total`'))
        ->join('depots AS d', 'd.id', '=', 'm.depot_id')
        ->where('m.verified', 1)
        ->groupBy(DB::raw('m.depot_id'))
        ->orderBy(\DB::raw('count(m.verified)'), 'DESC')
        ->get();;

        return $this->sendResponse($records, 'getTopDepotVerifiedMachines');

    }

    public function updateStaffName(Request $request){
        $data = $request->all();
        $staffId = $data["staffId"];
        $updatedName = $data["updatedName"];

        DB::table('staffs')->where('id', $staffId)->update(['name' => $updatedName]);
        DB::table("sync_records2")->where([
            ["name", "staffs"],
            ["data_id", $staffId]
        ])->delete();
        return $this->sendResponse("...", 'updateStaffName');
    }

    public function dealerMachines(Request $request){
        $data = $request->all();
        $dealerId = $data["dealerId"];

        $machines = DB::table("machines AS m")
        ->select('m.id', 'm.lat', 'm.lng', 'm.delivery')->where("m.staff_id", $dealerId)->whereNotNull('m.lat')->get();
        return $this->sendResponse($machines->toArray(), 'dealerMachines');
    }

    public function setMachineDelivery(Request $request){
        $data = $request->all();
        $ids = $data["ids"];
        $delivery = $data["delivery"];

        DB::table('machines')->whereIn('id', $ids)->update(['delivery' => $delivery]);
        return $this->sendResponse(null, 'setMachineDelivery');
    }

    public function setMachineVerification(Request $request){
        $data = $request->all();
        $machineId = $data["machineId"];
        $verified = $data["verified"];

        DB::table('machines')->where('id', $machineId)->update(['verified' => $verified]);
        return $this->sendResponse(null, 'setMachineVerification');
    }

    public function deleteMachine(Request $request){
        $data = $request->all();
        $machineId = $data["machineId"];

        DB::table('machines')->where('id', $machineId)->delete();
        DB::table('attachments')->where([['reference_id', $machineId], ['module_id', 5]])->delete();
        DB::table('callsheets')->where('machine_id', $machineId)->delete();
        DB::table('callsheets')->where('machine_id', $machineId)->delete();
        DB::table('cell_triggers')->where('machine_id', $machineId)->delete();
        DB::table('converted_synchs2')->where([['converted_id', $machineId], ['table', 'machines']])->delete();
        DB::table('surveys')->where('machine_id', $machineId)->delete();
        DB::table('wifi_triggers')->where('machine_id', $machineId)->delete();

        return $this->sendResponse(null, 'setMachineVerification');
    }

    public function productivityView(Request $request){
        $data = $request->all();
        $dealerId = $data["dealerId"];
        $startDate = $data["startDate"];
        $endDate = $data["endDate"];
        $year = $data["year"];
        $month = $data["month"];
        $type = $data["type"];

        $records = array();
        $visits = array();


        $records = DB::table("callsheets AS cs")->select(DB::raw("DATE(cs.created_at) AS `csDate`"), DB::raw("MIN(cs.created_at) AS `firstCall`"), DB::raw("MAX(cs.created_at) AS `lastCall`"))
        ->whereMonth('cs.created_at', $month)
        ->whereYear('cs.created_at', $year)
        ->where("cs.staff_id", $dealerId)
        ->groupBy(DB::raw('Date(cs.created_at)'))
        ->get();

        $visits = DB::table("callsheets AS cs")
        ->select("cs.*", "m.municipal", "m.brgy")
        ->whereMonth('cs.created_at', $month)
        ->whereYear('cs.created_at', $year)
        ->where("cs.staff_id", $dealerId)
        ->join('machines AS m', 'm.id', '=', 'cs.machine_id')
        ->get();

        foreach($records AS $record){
            $start = $record->csDate . " 00:00:00";
            $end = $record->csDate . " 23:59:59";
            $f = Carbon::parse($record->csDate)->subDays(31);

            $machinesCountToday = DB::table("machines AS m")
            ->whereRaw("m.delivery = DATE_FORMAT('".$record->csDate."', '%a') AND m.created_at <= DATE('".$end."') AND m.staff_id = '".$dealerId."' AND DATEDIFF('".$record->csDate."', COALESCE(DATE((SELECT created_at FROM callsheets WHERE created_at <= '".$end."' AND machine_id = m.id ORDER BY id DESC LIMIT 1)), NOW()) ) < 32")
            ->count();

            $machinesIds = DB::table("machines AS m")->select("m.id")
            ->whereRaw("m.delivery = DATE_FORMAT('".$record->csDate."', '%a') AND m.created_at <= DATE('".$end."') AND m.staff_id = '".$dealerId."' AND DATEDIFF('".$record->csDate."', COALESCE(DATE((SELECT created_at FROM callsheets WHERE created_at <= '".$end."' AND machine_id = m.id ORDER BY id DESC LIMIT 1)), NOW()) ) < 32")
            ->get();

            $visitsCountToday = DB::table("callsheets AS cs")
            ->whereRaw("cs.staff_id = '$dealerId' AND (cs.created_at >= '" . $start . "' AND cs.created_at <= '" . $end . "') AND cs.machine_id IN (SELECT id FROM machines m WHERE m.delivery = DATE_FORMAT('".$record->csDate."', '%a') AND m.created_at <= DATE('".$end."') AND m.staff_id = '".$dealerId."' AND DATEDIFF('".$record->csDate."', COALESCE(DATE((SELECT created_at FROM callsheets WHERE created_at <= '".$end."' AND machine_id = m.id ORDER BY id DESC LIMIT 1)), NOW()) ) < 32)")
            ->count(DB::raw('DISTINCT cs.machine_id'));

            $record->machinesCountToday = $machinesCountToday;
            $record->visitsCountToday = $visitsCountToday;
            $record->ids = $machinesIds;
        }



        return $this->sendResponse(array("records" => $records, "visits" => $visits), 'productivityView');

    }

    public function getReceipt(Request $request){
        $data = $request->all();
        $id = $data["id"];

        $receiptInfo = DB::table("callsheets AS cs")->select('c.name', 'cnt.contact', 'm.id AS machineId', 'c.id AS clientId', "cs.amount")
        ->join('machines AS m', 'm.id', '=', 'cs.machine_id')
        ->join('clients AS c', 'c.id', '=', 'm.client_id')
        ->join('contacts AS cnt', 'cnt.reference_id', '=', 'm.client_id')
        ->where([['cnt.module_id', 3], ['cs.id', $id]])
        ->first();

        $machineCount = DB::table("machines")->where("client_id", $receiptInfo->clientId)->count();
        $orderCount = DB::table("callsheets")->where([["machine_id", $receiptInfo->machineId], ["name", "Sale"]])->count();
        $orderCountAll = DB::table("callsheets")->where("name", "Sale")
        ->whereIn('machine_id', function($query) use($receiptInfo){
            $query->select("id")
                ->from('machines')
                ->where("client_id", $receiptInfo->clientId);
        })
        ->count();

        $clientPhoto = DB::table("attachments")->where([["module_id", 3], ["reference_id", $receiptInfo->clientId]])->first();
        if($clientPhoto){
            $ct = Image::make($clientPhoto->b64);
            $ct->resize(100, null, function ($constraint) {
                $constraint->aspectRatio();
            });
    
            $t = (string) $ct->encode('data-url');
            $clientPhoto = $t;
        }

        $orderItems = DB::table("inventories AS inv")->select("p.thumbnail", "p.name AS productName", "inv.qty", "inv.price", "inv.cost")->where([["module_id", 7], ["reference_id", $id]])
        ->join('products AS p', 'p.id', '=', 'inv.product_id')
        ->get();

        return $this->sendResponse(array("receiptInfo" => $receiptInfo, "clientPhoto" => $clientPhoto, "orderItems" => $orderItems, "machineCount" => $machineCount, "orderCount" => $orderCount, "orderCountAll" => $orderCountAll), 'getReceipt');
    }

    public function getTopLocations(){
        $region = DB::table("machines AS m")->select(DB::raw('IFNULL(m.region, "Unknown Region") AS `region`, COUNT(*) AS `total`, (SELECT name FROM depots WHERE id = m.depot_id) AS `depot_name`'))->orderBy(\DB::raw('count(*)'), 'DESC')->groupBy('m.depot_id','m.region')->limit(10)->get();
        $province = DB::table("machines AS m")->select(DB::raw('IFNULL(m.province, "Unknown Province") AS `province`, COUNT(*) AS `total`, (SELECT name FROM depots WHERE id = m.depot_id) AS `depot_name`'))->orderBy(\DB::raw('count(*)'), 'DESC')->groupBy('m.depot_id', 'm.region', 'm.province')->limit(10)->get();
        $municipal = DB::table("machines AS m")->select(DB::raw('IFNULL(m.municipal, "Unknown Municipal") AS `municipal`, COUNT(*) AS `total`, (SELECT name FROM depots WHERE id = m.depot_id) AS `depot_name`'))->orderBy(\DB::raw('count(*)'), 'DESC')->groupBy('m.depot_id', 'm.region', 'm.province', 'm.municipal')->limit(10)->get();
        $brgy = DB::table("machines AS m")->select(DB::raw('IFNULL(m.brgy, "Unknown Barangay") AS `brgy`, COUNT(*) AS `total`, (SELECT name FROM depots WHERE id = m.depot_id) AS `depot_name`'))->orderBy(\DB::raw('count(*)'), 'DESC')->groupBy('m.depot_id', 'm.region', 'm.province', 'm.municipal', 'm.brgy')->limit(10)->get();
        return $this->sendResponse(array("region" => $region, "province" => $province, "municipal" => $municipal, "brgy" => $brgy), 'getTypeofMachinesCount');;
    }

    public function getTypeofMachinesCount(){
        $machinesTotal = DB::table("machines")->count();
        $q = DB::table("machines")->select(DB::raw('IFNULL(machine_type, "Other") AS `name`, COUNT(*) AS `y`'))->orderBy(\DB::raw('count(*)'), 'DESC')->groupBy('machine_type')->get();
        foreach($q AS $key => $v){
            if($key == 0){
                $v->sliced = true;
                $v->selected = true;
            }

            $v->y = ($v->y / $machinesTotal) * 100;
        }
        return $this->sendResponse($q, 'getTypeofMachinesCount');
    }

    public function getMachinesSummary(Request $request){
        $expDate = Carbon::now()->addDays(30);
        $leadTotal = DB::table("machines")->whereNull('client_id')->count();
        $prospectTotal = DB::table("machines AS m")->where(function ($query) {
            $query->whereNotNull('m.client_id');
        })->whereRaw('(SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) = 0')->count();
        $activeTotal = DB::table("machines AS m")->where(function ($query) {
            $query->whereNotNull('m.client_id');
        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) < 31 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->count();
        $inactiveTotal = DB::table("machines AS m")->where(function ($query) {
            $query->whereNotNull('m.client_id');
        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) > 30 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->count();
        $unknownLocationsTotal = DB::table("machines AS m")->orWhereNull('m.lat')->where(function ($query) {
            $query->orWhereNull('m.region');
        })->count();
        $verifiedTotal = DB::table("machines")->where("verified", 1)->count();

        return $this->sendResponse(array("leadTotal" => $leadTotal, "prospectTotal" => $prospectTotal, "activeTotal" => $activeTotal, "inactiveTotal" => $inactiveTotal, "unknownLocationsTotal" => $unknownLocationsTotal, "verifiedTotal" => $verifiedTotal), 'getMachinesSummary');
    }

    public function getDashboardFirstBatchTop(Request $request){
        $machinesTotal = DB::table("machines")->count();
        $clientsTotal = DB::table("clients")->count();
        $dealersTotal = DB::table("staffs")->where("role_id", 3)->count();
        $depotTotal = DB::table("depots")->count();
        return $this->sendResponse(array("machinesTotal" => $machinesTotal, "clientsTotal" => $clientsTotal, "dealersTotal" => $dealersTotal, "depotTotal" => $depotTotal), 'getMachinesTotal');
    }

    public function clientFilter(Request $request){
        if ($request->isMethod('post')) {
            $data = $request->all();
            $export = false;

            $c = DB::table('data_storage')->where([['staff_id', $data["staff_id"]], ['trigger', 'clientFilter']])->select("id")->first();
            if($c){
                DB::table('data_storage')
                ->where('id', $c->id)
                ->update(['data' => json_encode($data)]);
            }else{
                DB::table('data_storage')->insert(
                    ['data' => json_encode($data), 'staff_id' => $data["staff_id"], 'trigger' => 'clientFilter']
                );
            }

        }else{
            $data = $request->all();
            $f = DB::table("data_storage")->select('data')->where([["staff_id", $data["staff_id"]], ["trigger", "clientFilter"]])->first();
            $data = json_decode($f->data, true);
            $export = true;
        }

        $depot = $data["depot"];
        $dealerIds = $data["dealerIds"];
        $from = $data["from"];
        $to = $data["to"];
        $name = $data["name"];
        $alias = $data["alias"];
        $email = $data["email"];
        $contact = $data["contact"];
        $clientMeetupValue = $data["clientMeetupValue"];
        $notificationValue = $data["notificationValue"];
        $specialAccountValue = $data["specialAccountValue"];
        $clientId = $data["clientId"];
        $machines = $data["machines"];

        $params = [];

        $recordsTotal = 0;
        $recordsFiltered = 0;

        $params = $data["params"];
        $columns = $params["columns"];
        $orderBy = $params["orderBy"];
        $orderDir = $params["orderDir"];

        $filter = DB::table("clients AS c")->select('c.special_account')->join('depots AS d', 'd.id', '=', 'c.depot_id')->join('staffs AS s', 's.id', '=', 'c.staff_id')
        ->leftJoin('contacts AS cnt', 'cnt.reference_id', '=', 'c.id')
        ->orderBy($orderBy, $orderDir);
        foreach($columns AS $col){
            $filter = $filter->addSelect(DB::raw($col["data"]));
        }

        $recordsTotal = $filter->count();

        /* if($export){
            $filter = $filter->
            addSelect(DB::raw("DAYNAME(cs.created_at) AS `day`, (SELECT name FROM clients WHERE id = m.client_id) AS `client name`"));
        } */

        if($clientId){
            $filter->where("c.id", $clientId);
        }else{
            //depot Filter
            if(count($depot)){
                $filter->whereIn('c.depot_id', $depot);
            }

            //dealer filter
            if(count($dealerIds)){
                $filter->whereIn('c.staff_id', $dealerIds);
            }

            //date filter
            if($from){
                $filter->whereBetween('c.created_at', [$from." 00:00:00", $to." 23:59:59"]);
            }

            if($name){
                $filter->where('c.name', 'like', '%' . $name . '%');
            }

            if($alias){
                $filter->where('c.alias', 'like', '%' . $alias . '%');
            }

            if($email){
                $filter->where('c.email', 'like', '%' . $email . '%');
            }

            if($clientMeetupValue == "Yes"){
                $filter->where(function ($query) {
                    $query->whereNotNull("brgy_id");
                });
            }
    
            if($clientMeetupValue == "No"){
                $filter->where(function ($query) {
                    $query->whereNull("brgy_id");
                });
            }

            if($notificationValue == "Yes"){
                $filter->where("c.notification", 1);
            }
    
            if($notificationValue == "No"){
                $filter->where("c.notification", 0);
            }

            if($specialAccountValue == "Yes"){
                $filter->where("c.special_account", 1);
            }
    
            if($specialAccountValue == "No"){
                $filter->where("c.special_account", 0);
            }

            if($contact){
                $filter->where([['cnt.contact', 'like', '%' . $contact . '%'], ["cnt.module_id", 3]]);
            }

            if($machines){
                $filter->whereRaw("(SELECT count(m.id) FROM machines m WHERE m.client_id = c.id) = ?", [$machines]);
            }
        }

        if($export){
            /* (new Collection($default))->downloadExcel(
                "machines.xls",
                $writerType = null,
                $headings = false
            ); */
            $exportation = new ClientsExport($filter->get());
            return Excel::download($exportation, 'clients.xls');

        }else{
            $recordsFiltered += $filter->count();
            $filter = $filter->limit($params["length"])->offset($params["start"])->get();

            return $this->sendResponse(array("clients" => $filter, "recordsTotal" => $recordsTotal, "recordsFiltered" => $recordsFiltered), 'clientFilter');
        }
    }

    public function callsheetFilter(Request $request){
        if ($request->isMethod('post')) {
            $data = $request->all();
            $export = false;

            $c = DB::table('data_storage')->where([['staff_id', $data["staff_id"]], ['trigger', 'callsheetFilter']])->select("id")->first();
            if($c){
                DB::table('data_storage')
                ->where('id', $c->id)
                ->update(['data' => json_encode($data)]);
            }else{
                DB::table('data_storage')->insert(
                    ['data' => json_encode($data), 'staff_id' => $data["staff_id"], 'trigger' => 'callsheetFilter']
                );
            }

        }else{
            $data = $request->all();
            $f = DB::table("data_storage")->select('data')->where([["staff_id", $data["staff_id"]], ["trigger", "callsheetFilter"]])->first();
            $data = json_decode($f->data, true);
            $export = true;
        }

        $depot = $data["depot"];
        $dealerIds = $data["dealerIds"];
        $csFrom = $data["csFrom"];
        $csTo = $data["csTo"];
        $actions = $data["actions"];
        $message = $data["message"];
        $amount = $data["amount"];
        $machineId = $data["machineId"];

        $params = [];

        $recordsTotal = 0;
        $recordsFiltered = 0;

        $params = $data["params"];
        $columns = $params["columns"];
        $orderBy = $params["orderBy"];
        $orderDir = $params["orderDir"];
        $callsheetsFilter = DB::table("callsheets AS cs")->join('depots AS d', 'd.id', '=', 'cs.depot_id')->join('staffs AS s', 's.id', '=', 'cs.staff_id')->join('machines AS m', 'm.id', '=', 'cs.machine_id')->orderBy($orderBy, $orderDir);
        foreach($columns AS $col){
            $callsheetsFilter = $callsheetsFilter->addSelect(DB::raw($col["data"]));
        }

        $recordsTotal = $callsheetsFilter->count();

        if($export){
            $callsheetsFilter = $callsheetsFilter->
            addSelect(DB::raw("DAYNAME(cs.created_at) AS `day`, (SELECT name FROM clients WHERE id = m.client_id) AS `client name`"));
        }

        if($machineId){
            $callsheetsFilter->where("cs.machine_id", $machineId);
        }else{
            //depot Filter
            if(count($depot)){
                $callsheetsFilter->whereIn('cs.depot_id', $depot);
            }

            //dealer filter
            if(count($dealerIds)){
                $callsheetsFilter->whereIn('cs.staff_id', $dealerIds);
            }

            //date filter
            if($csFrom){
                $callsheetsFilter->whereBetween('cs.created_at', [$csFrom." 00:00:00", $csTo." 23:59:59"]);
            }

            //actions filter
            if(count($actions)){
                $callsheetsFilter->whereIn('cs.name', $actions);
            }

            //dealer filter
            if($message){
                $callsheetsFilter->where('cs.message', 'like', '%' . $message . '%');
            }

            if($amount){
                $callsheetsFilter->where('cs.amount', $amount);
            }
        }

        $exportFilter = clone $callsheetsFilter;

        if($export){
            /* (new Collection($default))->downloadExcel(
                "machines.xls",
                $writerType = null,
                $headings = false
            ); */
            $exportation = new MachinesExport($exportFilter->get());
            return Excel::download($exportation, 'callsheets.xls');

        }else{
            $recordsFiltered += $callsheetsFilter->count();
            return $this->sendResponse(array("callsheets" => $callsheetsFilter->limit($params["length"])->offset($params["start"])->get(), "recordsTotal" => $recordsTotal, "recordsFiltered" => $recordsFiltered), 'callsheetFilter');
        }
    }

    public function machineFilter(Request $request){
        if ($request->isMethod('post')) {
            $data = $request->all();
            $export = false;

            $c = DB::table('data_storage')->where([['staff_id', $data["staff_id"]], ['trigger', 'machineFilter']])->select("id")->first();
            if($c){
                DB::table('data_storage')
                ->where('id', $c->id)
                ->update(['data' => json_encode($data)]);
            }else{
                DB::table('data_storage')->insert(
                    ['data' => json_encode($data), 'staff_id' => $data["staff_id"], 'trigger' => 'machineFilter']
                );
            }

        }else{
            $data = $request->all();
            $f = DB::table("data_storage")->select('data')->where([["staff_id", $data["staff_id"]], ["trigger", "machineFilter"]])->first();
            $data = json_decode($f->data, true);
            $export = true;
        }

        $depot = (isset($data["depot"])) ? $data["depot"] : [];
        $dealerIds = (isset($data["dealerIds"])) ? $data["dealerIds"] : [];
        $machineFrom = $data["machineFrom"];
        $machineTo = $data["machineTo"];
        $delivery = (isset($data["delivery"])) ? $data["delivery"] : [];
        $status = (isset($data["status"])) ? $data["status"] : [];
        $machineType = (isset($data["machineType"])) ? $data["machineType"] : [];
        $establishments = (isset($data["establishments"])) ? $data["establishments"] : [];
        $selectedRegion = $data["selectedRegion"];
        $selectedProvince = $data["selectedProvince"];
        $selectedMunicipal = $data["selectedMunicipal"];
        $selectedBrgy = $data["selectedBrgy"];
        $accuracy = $data["accuracy"];
        $accuracyOperator = $data["accuracyOperator"];
        $wifiTriggerValue = $data["wifiTriggerValue"];
        $cellTriggerValue = $data["cellTriggerValue"];
        

        $whereArray = [];
        $lead = [];
        $default = [];
        $prospect = [];
        $active = [];
        $inactive = [];
        $verified = $data["verified"];

        $additionalParams = (isset($data["params"])) ? true : false;
        $params = [];

        $recordsTotal = 0;
        $recordsFiltered = 0;
        
        $expDate = Carbon::now()->addDays(30);

        if($additionalParams){
            $params = $data["params"];
            $columns = $params["columns"];
            $orderBy = $params["orderBy"];
            $orderDir = $params["orderDir"];
            $machineFilter = DB::table("machines AS m")->join('depots AS d', 'd.id', '=', 'm.depot_id')->join('staffs AS s', 's.id', '=', 'm.staff_id')->orderBy($orderBy, $orderDir);
            foreach($columns AS $col){
                $machineFilter = $machineFilter->addSelect(DB::raw($col["data"]));
            }
            $recordsTotal = $machineFilter->count();

            if($export){
                $machineFilter = $machineFilter->
                addSelect(DB::raw("m.client_id, m.updated_at, m.accuracy, m.delivery, IF(ISNULL(m.lat), '', CONCAT('https://maps.google.com?q=', m.lat, ',', m.lng)) AS `map`, m.lat AS `latitude`, m.lng AS `longitude`, m.machine_type, m.region, m.province, m.municipal, m.brgy, IF(m.verified, 'YES', 'NO') AS `verified`, (SELECT name FROM clients WHERE id = m.client_id) AS `client_name`, (SELECT contact FROM contacts WHERE reference_id = m.client_id AND module_id = 3) AS `contact`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Sale' AND machine_id = m.id) AS `Sale`, (SELECT COUNT(*) FROM callsheets WHERE name = 'No Sale' AND machine_id = m.id) AS `No Sale`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Repair' AND machine_id = m.id) AS `Repair`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Cleaning' AND machine_id = m.id) AS `Cleaning`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Calibration' AND machine_id = m.id) AS `Calibration`, (SELECT COUNT(*) FROM callsheets WHERE name = 'No Action' AND machine_id = m.id) AS `No Action`, (SELECT created_at FROM callsheets WHERE machine_id = m.id ORDER BY id DESC LIMIT 1) AS `Last transaction`, CASE 
                WHEN m.client_id IS NULL THEN 'Lead' 
                WHEN ((SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) = 0 AND m.client_id IS NOT NULL) THEN 'Prospect' 
                WHEN (DATEDIFF('". $expDate ."', (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) < 31 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0 AND m.client_id IS NOT NULL) THEN 'Active' 
                WHEN (DATEDIFF('". $expDate ."', (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) > 30 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0 AND m.client_id IS NOT NULL) THEN 'Inactive' 
                ELSE '...' END AS `status`"));
            }
        }
            
        else{
            $machineFilter = DB::table("machines AS m")->select('m.id', 'm.lat', 'm.lng')->whereNotNull('m.lat');

        }

        //depot Filter
        if(count($depot)){
            $machineFilter->whereIn('m.depot_id', $depot);
        }

        //dealer filter
        if(count($dealerIds)){
            $machineFilter->whereIn('m.staff_id', $dealerIds);
        }

        //date filter
        if($machineFrom){
            $machineFilter->whereBetween('m.created_at', [$machineFrom." 00:00:00", $machineTo." 23:59:59"]);
        }

        //deliver filter
        if(count($delivery)){
            $machineFilter->whereIn('m.delivery', $delivery);
        }

        //machine Type
        if(count($machineType)){
            $machineFilter->whereIn("m.machine_type", $machineType);
        }

        //machine Type
        if($establishments){
            $machineFilter->whereIn('m.id', function($query) use ($establishments){
                $query->select('e.machine_id')
                  ->from("establishments AS e")
                  ->whereIn('e.name', $establishments)
                  ->whereRaw('e.machine_id = m.id');
             });
        }

        if($selectedRegion){
            $machineFilter->where("m.region", $selectedRegion);;
            //array_push($whereArray, ["m.region", $selectedRegion]);
        }

        if($selectedProvince){
            $machineFilter->where("m.province", $selectedProvince);;
            //array_push($whereArray, ["m.region", $selectedRegion]);
        }

        if($selectedMunicipal){
            $machineFilter->where("m.municipal", $selectedMunicipal);;
            //array_push($whereArray, ["m.region", $selectedRegion]);
        }

        if($selectedBrgy){
            $machineFilter->where("m.brgy", $selectedBrgy);;
            //array_push($whereArray, ["m.region", $selectedRegion]);
        }

        $machineFilter->where(function ($query) use ($accuracy, $accuracyOperator) {
            $op = ($accuracyOperator == "greaterThan") ? ">=" : "<=";
            $query->where("m.accuracy", $op, ($accuracy) ? $accuracy : 0);
            $query->orWhereNull('m.accuracy');
        });

        if($wifiTriggerValue == "Yes"){
            $machineFilter->where(function ($query) {
                $query->whereRaw("(SELECT count(*) FROM wifi_triggers w WHERE w.machine_id = m.id) > 0");
            });
        }

        if($wifiTriggerValue == "No"){
            $machineFilter->where(function ($query) {
                $query->whereRaw("(SELECT count(*) FROM wifi_triggers w WHERE w.machine_id = m.id) = 0");
            });
        }

        if($cellTriggerValue == "Yes"){
            $machineFilter->where(function ($query) {
                $query->whereRaw("(SELECT count(*) FROM cell_triggers c WHERE c.machine_id = m.id) > 0");
            });
        }

        if($cellTriggerValue == "No"){
            $machineFilter->where(function ($query) {
                $query->whereRaw("(SELECT count(*) FROM cell_triggers c WHERE c.machine_id = m.id) = 0");
            });
        }

        if($verified == "Yes"){
            $machineFilter->where("m.verified", 1);
        }

        if($verified == "No"){
            $machineFilter->where("m.verified", 0);
        }

        if(count($status)){
            
            if(in_array("Lead", $status) && in_array("Prospect", $status) && in_array("Active", $status) && in_array("Inactive", $status)){
                if($additionalParams && !$export){
                    $recordsFiltered += $default->count();
                    $default = $machineFilter->limit($params["length"])->offset($params["start"])->get();
                }
                else{
                    $default = $machineFilter->get();
                }  
            }else{
                if (in_array("Lead", $status)){
                    $lead = clone $machineFilter;

                    if($additionalParams && !$export){
                        $recordsFiltered += $lead->whereNull('m.client_id')->count();
                        $lead = $lead->whereNull('m.client_id')->limit($params["length"])->offset($params["start"])->get();
                    }
                        
                    else
                        $lead = $lead->whereNull('m.client_id')->get();
                }
    
                if (in_array("Prospect", $status)){
                    $prospect = clone $machineFilter;
                    if($additionalParams && !$export){
                        $recordsFiltered += $prospect->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('(SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) = 0')->count();
                        $prospect = $prospect->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('(SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) = 0')->limit($params["length"])->offset($params["start"])->get();
                    }
                    
                    else{
                        $prospect = $prospect->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('(SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) = 0')->get();
                    }
                }
    
                if (in_array("Active", $status)){
                    $active = clone $machineFilter;
                    if($additionalParams && !$export){
                        $recordsFiltered += $active->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) < 31 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->count();
                        $active = $active->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) < 31 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->limit($params["length"])->offset($params["start"])->get();
                    }
                        
                    else
                        $active = $active->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) < 31 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->get();
                }
    
                if (in_array("Inactive", $status)){
                    $inactive = clone $machineFilter;
                    if($additionalParams && !$export){
                        $recordsFiltered += $inactive->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) > 30 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->count();
                        $inactive = $inactive->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) > 30 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->limit($params["length"])->offset($params["start"])->get();
                    }
                        
                    else
                        $inactive = $inactive->where(function ($query) {
                            $query->whereNotNull('m.client_id');
                        })->whereRaw('DATEDIFF("'. $expDate .'", (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) > 30 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0')->get();
                }
            }
        }

        else{
            if($additionalParams && !$export){
                $recordsFiltered += $machineFilter->count();
                $default = $machineFilter->limit($params["length"])->offset($params["start"])->get();
                
            }
            else{
                $default = $machineFilter->get();
            }
                
        }

        if($export){
            /* (new Collection($default))->downloadExcel(
                "machines.xls",
                $writerType = null,
                $headings = false
            ); */
            $collections = new Collection();
            if(count($default)){
                $collections = $collections->merge($default);
            }
                
            if(count($lead)){
                $collections = $collections->merge($lead);
            }
                
            if(count($prospect)){
                $collections = $collections->merge($prospect);
            }
                
            if(count($active)){
                $collections = $collections->merge($active);
            }
                
            if(count($inactive)){
                $collections = $collections->merge($inactive);
            }
                
            if(count($inactive)){
                $collections = $collections->merge($inactive);
            }
                
            
            $exportation = new MachinesExport($collections);
            return Excel::download($exportation, 'machines.xls');

        }
        else{
            return $this->sendResponse(array("default" => $default, "lead" => $lead, "prospect" => $prospect, "active" => $active, "inactive" => $inactive, "recordsTotal" => $recordsTotal, "recordsFiltered" => $recordsFiltered), 'machineFilter');
        }
        
    }

    public function dealerMachinesSchedule(Request $request){
        $data = $request->all();
        $dealerId = $data["dealerId"];
        $expDate = Carbon::now()->addDays(30);
        $machineFilter = DB::table("machines AS m")->join('depots AS d', 'd.id', '=', 'm.depot_id')->join('staffs AS s', 's.id', '=', 'm.staff_id')->where("staff_id", $dealerId);
        $machineFilter = $machineFilter->
                addSelect(DB::raw("m.id, m.client_id, m.updated_at, m.accuracy, m.delivery, IF(ISNULL(m.lat), '', CONCAT('https://maps.google.com?q=', m.lat, ',', m.lng)) AS `map`, m.lat AS `latitude`, m.lng AS `longitude`, m.machine_type, m.region, m.province, m.municipal, m.brgy, IF(m.verified, 'YES', 'NO') AS `verified`, (SELECT name FROM clients WHERE id = m.client_id) AS `client_name`, (SELECT contact FROM contacts WHERE reference_id = m.client_id AND module_id = 3) AS `contact`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Sale' AND machine_id = m.id) AS `Sale`, (SELECT COUNT(*) FROM callsheets WHERE name = 'No Sale' AND machine_id = m.id) AS `No Sale`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Repair' AND machine_id = m.id) AS `Repair`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Cleaning' AND machine_id = m.id) AS `Cleaning`, (SELECT COUNT(*) FROM callsheets WHERE name = 'Calibration' AND machine_id = m.id) AS `Calibration`, (SELECT COUNT(*) FROM callsheets WHERE name = 'No Action' AND machine_id = m.id) AS `No Action`, (SELECT created_at FROM callsheets WHERE machine_id = m.id ORDER BY id DESC LIMIT 1) AS `Last transaction`, CASE 
                WHEN m.client_id IS NULL THEN 'Lead' 
                WHEN ((SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) = 0 AND m.client_id IS NOT NULL) THEN 'Prospect' 
                WHEN (DATEDIFF('". $expDate ."', (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) < 31 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0 AND m.client_id IS NOT NULL) THEN 'Active' 
                WHEN (DATEDIFF('". $expDate ."', (SELECT created_at FROM callsheets WHERE callsheets.machine_id = m.id ORDER BY id DESC LIMIT 1)) > 30 AND (SELECT COUNT(*) FROM callsheets cs WHERE cs.machine_id = m.id) > 0 AND m.client_id IS NOT NULL) THEN 'Inactive' 
                ELSE '...' END AS `status`"))->get();

        return $this->sendResponse($machineFilter, 'dealerMachinesSchedule');
    }

    public function getProvinceList(Request $request){
        $data = $request->all();
        $region = $data["region"];

        $province = DB::table("locations")->distinct()->select("id_1 AS value", "province AS label")->where('region', $region)->get();
        return $this->sendResponse($province, 'getProvinceList');
    }

    public function getMunicipalList(Request $request){
        $data = $request->all();
        $provinceId = $data["provinceId"];

        $municipals = DB::table("locations")->distinct()->select("id_2 AS value", "name_2 AS label")->where('id_1', $provinceId)->get();
        return $this->sendResponse($municipals, 'getMunicipalList');
    }

    public function getBrgyList(Request $request){
        $data = $request->all();
        $municipalId = $data["municipalId"];

        $brgys = DB::table("locations")->distinct()->select("id_3 AS value", "name_3 AS label", "varname_3 AS alias")->where('id_2', $municipalId)->get();
        return $this->sendResponse($brgys, 'getBrgyList');
    }

    public function getCallsheets(Request $request){
        $data = $request->all();
        $limit = $data["limit"];
        $wherearray = array();

        if(isset($data["machineId"])){
            array_push($wherearray, ['machine_id', $data["machineId"]]);
        }

        $c = DB::table("callsheets")->where($wherearray)->orderBy('id', 'desc')->limit($limit);

        $callsheets = $c->get();
        $count = $c->count();
        return $this->sendResponse(array("calls" => $callsheets, "count" => $count), 'getCallsheets');
    }

    public function getClientProfile(Request $request){
        $data = $request->all();
        $id = $data["id"];

        $client = null;
        $clientContact = null;
        $clientPhoto = null;
        $clientLocation = null;

        $machines = array();

        $client = DB::table("clients")->where('id', $id)->first();
        $depot = DB::table("depots")->select("name")->where('id', $client->depot_id)->first();
        $dealer = DB::table("staffs")->select("name", "contact", "thumbnail", "email")->where('id', $client->staff_id)->first();

        if($client->brgy_id){
            $clientLocation = DB::table("locations")->select(DB::raw("ST_AsGeoJSON(SHAPE) AS geo, region, province, name_2 AS municipal, name_3 AS brgy, varname_3, id_3 as brgyID"))->where('id_3', $client->brgy_id)->first();
        }

        $clientContact = DB::table("contacts")->where([["module_id", 3], ["reference_id", $id]])->first();
        $clientPhoto = DB::table("attachments")->select('b64_preview')->where([["module_id", 3], ["reference_id", $id]])->first();
        if($clientPhoto){
            $resizedThumbnail = Image::make($clientPhoto->b64_preview);
            $resizedThumbnail->resize(100, 100);

            $t = (string) $resizedThumbnail->encode('data-url');
            $clientPhoto = $t;
        }

        $m = DB::table("machines AS m")->where("m.client_id", $client->id)->get();
        foreach($m AS $machine){
            $machinePhoto = DB::table("attachments")->where([["module_id", 5], ["reference_id", $machine->id]])->first();
            $sale = DB::table("callsheets")->where([["name", "Sale"], ["machine_id", $machine->id]])->count();
            $noSale = DB::table("callsheets")->where([["name", "No Sale"], ["machine_id", $machine->id]])->count();
            $repair = DB::table("callsheets")->where([["name", "Repair"], ["machine_id", $machine->id]])->count();
            if($machinePhoto){
                $ct = Image::make($machinePhoto->b64);
                $ct->resize(337, null, function ($constraint) {
                    $constraint->aspectRatio();
                });
        
                $t = (string) $ct->encode('data-url');
                $machinePhoto = $t;
            }
            $machine->thumbnail = $machinePhoto;
            $machine->sale = $sale;
            $machine->noSale = $noSale;
            $machine->repair = $repair;
            $machines[] = $machine;

        }

        return $this->sendResponse(array("client" => $client, "clientContact" => $clientContact, "clientPhoto" => $clientPhoto, "dealer" => $dealer, "depot" => $depot, "clientLocation" => $clientLocation, "machines" => $machines), 'getClientProfile');
    }

    public function getMachineProfile(Request $request){
        $data = $request->all();
        $id = $data["id"];

        $client = null;
        $clientContact = null;
        $clientPhoto = null;
        $clientLocation = null;
        $status = "Lead";
        
        $lastSaleTransaction = DB::table("callsheets")->where([['machine_id', $id], ["name", "Sale"]])->orderBy('id', 'desc')->limit(1)->first();
        if($lastSaleTransaction){
            $myTime = Carbon::now();
            $lastSaleTransaction->diffInDays = $myTime->diffInDays($lastSaleTransaction->created_at);
        }

        $machine = DB::table("machines")->where('id', $id)->first();
        $depot = DB::table("depots")->select("name")->where('id', $machine->depot_id)->first();
        $dealer = DB::table("staffs")->select("name", "contact", "thumbnail", "email")->where('id', $machine->staff_id)->first();
        if($dealer->thumbnail){
            $resizedThumbnail = Image::make($dealer->thumbnail);
            $resizedThumbnail->resize(100, 100);

            $t = (string) $resizedThumbnail->encode('data-url');
            $dealer->thumbnail = $t;
        }
        $establishments = DB::table("establishments")->where('machine_id', $id)->get();
        $callsheets = DB::table("callsheets")->select('name', DB::raw('ifnull(count(*), 0) as total'))->where('machine_id', $id)->groupBy('name')->get();
        $machinePhoto = DB::table("attachments")->where([["module_id", 5], ["reference_id", $id]])->first();
        if($machinePhoto){
            $ct = Image::make($machinePhoto->b64);
            $ct->resize(742, null, function ($constraint) {
                $constraint->aspectRatio();
            });
    
            $t = (string) $ct->encode('data-url');
            $machinePhoto = $t;
        }
        $wifiTriggers = DB::table("wifi_triggers")->where('machine_id', $id)->count();
        $cellTriggers = DB::table("cell_triggers")->where('machine_id', $id)->count();
        if($machine){
            $client = DB::table("clients")->where('id', $machine->client_id)->first();
            if($client){
                $status = "Prospect";

                if($lastSaleTransaction){
                    $status = "Active";
                    if($lastSaleTransaction->diffInDays > 30){
                        $status = "Inactive";
                    }
                }

                if($client->brgy_id){
                    $clientLocation = DB::table("locations")->select(DB::raw("ST_AsGeoJSON(SHAPE) AS geo, region, province, name_2 AS municipal, name_3 AS brgy, varname_3, id_3 as brgyID"))->where('id_3', $client->brgy_id)->first();
                }

                $clientContact = DB::table("contacts")->where([["module_id", 3], ["reference_id", $client->id]])->first();
                $clientPhoto = DB::table("attachments")->select('b64_preview')->where([["module_id", 3], ["reference_id", $id]])->first();
                if($clientPhoto){
                    $resizedThumbnail = Image::make($clientPhoto->b64_preview);
                    $resizedThumbnail->resize(100, 100);

                    $t = (string) $resizedThumbnail->encode('data-url');
                    $clientPhoto = $t;
                }
            }  
        }
            
        return $this->sendResponse(array("machine" => $machine, "client" => $client, "machinePhoto" => $machinePhoto, "clientContact" => $clientContact, "clientPhoto" => $clientPhoto, "wifiTriggers" => $wifiTriggers, "cellTriggers" => $cellTriggers, "callsheetsCount" => $callsheets, "establishments" => $establishments, "lastSaleTransaction" => $lastSaleTransaction, "status" => $status, "dealer" => $dealer, "depot" => $depot, "clientLocation" => $clientLocation), 'getMachineProfile');
    }

    public function machinesOnMap(Request $request){
        $data = $request->all();

        $machines = DB::table("machines")->select('id', 'lat', 'lng')->whereNotNull('lat')->get();
        return $this->sendResponse($machines->toArray(), 'machinesOnMap');
    }
    
    public function getMachines(Request $request){
        $data = $request->all();
        $records = DB::table("machines")->select('id', 'lat', 'lng')->whereNotNull('lat')->get();
        return $this->sendResponse($records->toArray(), 'Machines');

    }

    public function oneSignal(Request $request){
        $data = $request->all();
        OneSignal::sendNotificationToAll(
            "Some Message", 
            $url = null, 
            $data = null, 
            $buttons = null, 
            $schedule = null
        );
    }

    public function paymentStatus(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $payment_codes = DB::table("payment_codes")->where('staff_id', $staff_id)->orderBy('id', 'desc')->first();
        if($payment_codes){
            $message = ["exp" => $payment_codes->expiration];
        }else{
            $message = ["exp" => null];
        }

        $message = ["exp" => "2020-03-27 00:00:00"];

        return $this->sendResponse($message, '...');
    }

    public function pullClientDetails(Request $request){
        $data = $request->all();
        $clientId = $data["clientId"];
    }

    public function clientDetails(Request $request){
        $data = $request->all();
        $clientId = $data["clientId"];

        $realId = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $clientId],['table', "clients"]])->first();

        $records = DB::table("clients")->where('id', $realId->converted_id)->first();
        return $this->sendResponse($records, 'Client');

    }

    public function transferMachines(Request $request){
        $data = $request->all();
        $transferFrom = $data["transferFrom"];
        $transferTo = $data["transferTo"];
        $ids = $data["ids"];

        foreach($ids AS $id){
            $res = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $id],['table', "machines"]])->first();
            $converted_id = $res->converted_id;
            DB::table("machine_transfers")->updateOrInsert(['transferFrom' => $transferFrom, 'transferTo' => $transferTo, 'machine_id' => $converted_id], ['transferFrom' => $transferFrom, 'transferTo' => $transferTo, 'machine_id' => $converted_id]);
        }

        return $this->sendResponse($ids, 'transferMachines retrieved successfully.');
    }

    public function checkMachineTransfers(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $from = null;
        $fromId = null;
        $transferFromId = null;
        $transferToId = null;

        $transfersCount = DB::table("machine_transfers")->where([['transferTo', $staff_id], ['status', "pending"]])->count();
        if($transfersCount){
            $a = DB::table("machine_transfers")->where([['transferTo', $staff_id],['status', "pending"]])->first();
            $transferToId = $a->transferTo;
            $transferFromId = $a->transferFrom;
            $name = DB::table("staffs")->select("name")->where("id", $transferFromId)->first();
            $from = $name->name;
        }

        return $this->sendResponse(array("from" => $from, "counts" => $transfersCount, "fromId" => $transferFromId), 'checkMachineTransfers retrieved successfully.');
    }

    public function goMachineTransfers(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $fromId = $data["fromId"];

        $transfersCount = DB::table("machine_transfers")->where([['transferFrom', $fromId], ['transferTo', $staff_id], ['status', "pending"]])->count();
        if($transfersCount){
            $transfers = DB::table("machine_transfers")->where([['transferFrom', $fromId], ['transferTo', $staff_id],['status', "pending"]])->get();
            foreach($transfers AS $transfer){
                DB::table("machines")->where('id', $transfer->machine_id)
                ->update(['staff_id' => $staff_id]);
            }

            DB::table("machine_transfers")->where([["transferFrom", $fromId], ["transferTo", $staff_id], ["status", "pending"]])
                ->update(['status' => "complete"]);
        }

        return $this->sendResponse($transfersCount, 'checkMachineTransfers retrieved successfully.');
    }

    public function completeMachineTransfers(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $ids = [];
        $to = null;
        
        $q = DB::table("machine_transfers")->where([["transferFrom", $staff_id], ["status", "complete"]]);
        if($q->count()){
            $transfers = $q->get();
            $c = $q->first();
            $to = DB::table("staffs")->select("name")->where("id", $c->transferTo)->first();
            foreach($transfers AS $transfer){
                $res = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $transfer->machine_id],['table', "machines"]])->first();
                array_push($ids, $res->sync_id);
                DB::table('machine_transfers')->where('id', $transfer->id)->delete();
            }
        }

        return $this->sendResponse(array("to" => $to, "transferred" => $ids), 'checkMachineTransfers retrieved successfully.');;
    }

    
    public function dealerVersion(){
        $arr = array(); 
        $arr["version"] = 5.1;
        $arr["changelog"] = array(
            array("FIXED", "All installation errors"),
            array("ADDED", "Send SMS features thru delivery list")
        );
        $json = json_encode($arr, JSON_FORCE_OBJECT); 
        return $this->sendResponse($json, 'dealerVersion retrieved successfully.');
    }

    public function getLogin(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $staff_id = $data["staff_id"];

        $records = \App\Logins::where([["depot_id", $depot_id], ["staff_id", $staff_id]])
        ->whereDate('created_at', Carbon::today())
        ->get();

        return $this->sendResponse($records->toArray(), 'getLogin');
    }

    public function getDealersQuickList(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];

        $depotV = DB::table('staffs AS s')->select('s.id', 's.thumbnail', 's.name', DB::raw("(SELECT created_at FROM callsheets WHERE staff_id = s.id ORDER BY id DESC LIMIT 1) AS last_transaction"));
        
        if(is_array($depot_id)){
            $depot = $depotV->where('role_id', 3)->whereIn("depot_id", $depot_id)->get();
        }else{
            $depot = $depotV->where([['depot_id', $depot_id], ['role_id', 3]])->get();
        }

        foreach($depot AS $d){
            if($d->thumbnail){
                $resizedThumbnail = Image::make($d->thumbnail);
                $resizedThumbnail->resize(100, 100);
    
                $t = (string) $resizedThumbnail->encode('data-url');
                $d->thumbnail = $t;
            }
        }

        return $this->sendResponse($depot->toArray(), 'getDealersQuickList');
    }

    public function postProxy(Request $request){
        $data = $request->all();
        $endPoint = $data["endpoint"];
        $body = $data["body"];
        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json'
            ]
        ]);

        $response = $client->post($endPoint,
            ['body' => $body]
        );

       /*  echo $response->getBody()->read(4);
        echo $response->getBody()->read(4);
        echo $response->getBody()->read(1024); */

        return $this->sendResponse(json_decode($response->getBody()->read(1024)), 'setLogin');
    }

    public function setLogin(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $staff_id = $data["staff_id"];
        $created_at = $data["created_at"];

        $logins = new \App\Logins;

        $logins->depot_id = $depot_id;
        $logins->staff_id = $staff_id;
        $logins->created_at = $created_at;

        $logins->save();

        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'key=AAAAnwpjJ4A:APA91bGhQX_UvzPgzRYhoiowbvBzgSdftHXEB7niQqa0QmY-exWBE61eSNFIZ5SQBQfMqqF21LaGtSBxU7HFtUUX9QYeq1pyXoreHShhmeuAmelFkyFUnYg4JvkLdhvUYrccXKfPDxZF'
            ]
        ]);

        $records = DB::table("staffs")->select("staffs.name", 'depots.name AS depot_name', 'roles.display_name AS role_name')->join('roles', 'staffs.role_id', '=', 'roles.id')->join('depots', 'staffs.depot_id', '=', 'depots.id')->where("staffs.id", $staff_id)->first();

        $response = $client->post('https://fcm.googleapis.com/fcm/send',
        ['body' => json_encode(
            [
                'to' => '/topics/general',
                "collapse_key" => "New Messages",
                "notification" => array(
                    "body" => $records->name . " @ " . Carbon::createFromFormat('Y-m-d H:i:s', $created_at)->toDayDateTimeString(),
                    "title" => "DTR for " . $records->depot_name . " Depot",
                    "icon" => "https://baristachoi-server.firebaseapp.com/assets/images/fcm-icon.png",
                    "click_action" => "https://baristachoi-server.firebaseapp.com/"
                )
            ]
        )]);

        return $this->sendResponse([], 'setLogin');
    }

    public function checkKey(Request $request){
        $data = $request->all();
        $key = $data["key"];
        $id = $data["id"];

        $depot = DB::table('depots')->select('key')->where('id', $id)->first();

        if (Hash::check($key, $depot->key)) {
            return $this->sendResponse(true, 'Data retrieved successfully.');
        }else{
            return $this->sendResponse(false, 'Data retrieved successfully.');
        }
    }

    public function getRoles(){
        $roles = DB::table('roles')->select('id', 'display_name')->get();
        return $this->sendResponse($roles->toArray(), 'Roles retrieved successfully.');
    }

    public function getProductCategories(){
        $product_categories = DB::table('product_categories')->select('id', 'name', 'sequence')->get();
        return $this->sendResponse($product_categories->toArray(), 'product categories retrieved successfully.');
    }

    public function syncPullCount(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $all = ($data["all"] == "true") ? true : false;
        $staff_id = (int) $data["staff_id"];
        $depot_id = (int) $data["depot_id"];

        if($all){
            if(Schema::hasColumn($table, 'depot_id')){
                $records = DB::table($table)->where('depot_id', $depot_id)->count();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->count();
            }else{
                $records = DB::table($table)->count();
            }
        }
        else{
            if(Schema::hasColumn($table, 'depot_id'))
                $records = DB::table($table)->where('depot_id', $depot_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                        ->from('sync_records')
                        ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->count();

            else if(Schema::hasColumn($table, 'staff_id'))
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                        ->from('sync_records')
                        ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->count();
            else
                $records = DB::table($table)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                        ->from('sync_records')
                        ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->count();
        }

        return $this->sendResponse($records, 'records retrieved successfully.');
    }

    public function syncPull(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $all = ($data["all"] == "true") ? true : false;
        $skip = (int) $data["skip"];
        $staff_id = (int) $data["staff_id"];
        $depot_id = (int) $data["depot_id"];

        if($all){
            if(Schema::hasColumn($table, 'depot_id')){
                $records = DB::table($table)->where('depot_id', $depot_id)->skip($skip)->take(3)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->skip($skip)->take(3)->get();
            }else{
                $records = DB::table($table)->skip($skip)->take(3)->get();
            }
        }
        else{
            if(Schema::hasColumn($table, 'depot_id')){
                $records = DB::table($table)->where('depot_id', $depot_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(3)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(3)->get();
            }
            else{
                $records = DB::table($table)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(3)->get();
            }
        }
        
        $results = json_decode(json_encode($records), true);
        foreach($results as $result){
            if($staff_id != null)
                \App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $result["id"]]);
        }

        return $this->sendResponse($records->toArray(), 'records retrieved successfully.');
    }

    public function syncPullDealers(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $all = ($data["all"] == "true") ? true : false;
        $skip = (int) $data["skip"];
        $staff_id = (int) $data["staff_id"];
        $depot_id = (int) $data["depot_id"];
        $realIds = (isset($data["realIds"])) ? $data["realIds"] : array();
        //relational tables
        $relationalTables = $data["relTables"];
        //modules tables
        $moduleTables = $data["modules"];
        $singleData = $data["singleData"];

        if($all){
            if($table == "clients"){
                $records = DB::table($table)->where('staff_id', $staff_id)->skip($skip)->take(10)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->skip($skip)->take(10)->get();
            }else{
                $records = DB::table($table)->skip($skip)->take(10)->get();
            }
        }
        else{
            if($table == "clients"){
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records2')
                          ->whereRaw("sync_records2.name = ? AND sync_records2.staff_id = ? AND sync_records2.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }

            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records2')
                          ->whereRaw("sync_records2.name = ? AND sync_records2.staff_id = ? AND sync_records2.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }
            else{
                $records = DB::table($table)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records2')
                          ->whereRaw("sync_records2.name = ? AND sync_records2.staff_id = ? AND sync_records2.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }

            if(count($singleData) == 2){
                if($table == $singleData[0]){
                    $sData = DB::table($table)->where("id", $singleData[1])->get();
                    $records = $sData;
                }
            }
        }

        //$records = $records->toArray();
        $records = json_decode(json_encode($records), true);
        $rels = array();
        $mods = array();
        $newRecord = array();

        foreach($records AS $key => $record){
            if($staff_id != null)
                DB::table("sync_records2")->updateOrInsert(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record["id"]], ['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record["id"]]);
                //\App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record["id"]]);

            $resIdP = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record["id"]],['table', $table]])->first();
            $origId = (int) $resIdP->sync_id;

            if(isset($record["client_id"])){
                if($record["client_id"] != null){
                    $s = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record["client_id"]],['table', 'clients']])->first();
                $record["client_id"] = $s->sync_id;
                }
            }

            if(isset($record["machine_id"])){
                if($record["machine_id"] != null){
                    $s = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record["machine_id"]],['table', 'machines']])->first();
                $record["machine_id"] = $s->sync_id;
                }
            }
            
            foreach($relationalTables["tables"] AS $relationalTable){
                $relationalTableName = $relationalTable;
                $relationalCol = $relationalTables["relationalCol"];
                $res = DB::table($relationalTable)->where($relationalCol, $record["id"])->get();
                foreach($res AS $re){
                    $resId = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $re->id],['table', $relationalTableName]])->first();
                    $re->id = (int) $resId->sync_id;
                    $re->{$relationalCol} = $origId;
                    $re->table = $relationalTableName;
                    array_push($rels, $re);
                }
            }

            foreach($moduleTables["tables"] AS $moduleTable){
                $moduleTableName = $moduleTable;
                $moduleId = $moduleTables["module_id"];
                $res = DB::table($moduleTable)->where([["module_id", $moduleId], ["reference_id", $record["id"]]])->get();
                foreach($res AS $re){
                    $resId = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $re->id],['table', $moduleTableName]])->first();
                    $re->id = (int) $resId->sync_id;
                    $re->reference_id = $origId;
                    $re->table = $moduleTableName;
                    array_push($mods, $re);
                }
            }

            
            $record["id"] = $origId;
            $record["table"] = $table;

            foreach($realIds AS $realId){
                if($record[$realId["col"]] != null){
                    $resId = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record[$realId["col"]]],['table', $realId["table"]]])->first();
                    $record[$realId["col"]] = $resId->sync_id;
                }
            }
            array_push($newRecord, $record);
        }

        $results = array("records" => $newRecord, "modules" => $mods, "relations" => $rels);
    

        /* foreach($records as $record){
            if($staff_id != null)
                \App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record->id]);

            $resId = DB::table("converted_synchs")->select('sync_id')->where([['converted_id', $record->id],['table', $table]])->first();
            $record->id = (int) $resId->sync_id;

            if($table == "machines"){
                if($record->client_id != null){
                    $resId = DB::table("converted_synchs")->select('sync_id')->where([['converted_id', $record->client_id],['table', 'clients']])->first();
                    $record->client_id = $resId->sync_id;
                }
                
            }
        } */

        return $this->sendResponse($results, 'records retrieved successfully.');
    }

    public function syncPushDealers(Request $request){
        $data = $request->all();
        $records = $data["records"];
        $table = $data["table"];
        $recordIds = array();
        foreach($records AS $record){
            //get long ID
            $syncId = $record["id"];
            //staff ID
            $staff_id = $record["staff_id"];
            //relational tables
            $relationalTables = $record["tables"];
            //modules tables
            $moduleTables = $record["modules"];
            //check for new or update only
            $mode = (intval($record["sync"]) == 0) ? "new" : "update";
            //remove uneccessary properties
            unset($record["id"]);
            unset($record["sync"]);
            unset($record["tables"]);
            unset($record["modules"]);
            //Retrieve Converted IDs if any
            if(isset($record["client_id"])){
                if($record["client_id"] != null){
                    $s = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $record["client_id"]],['table', 'clients']])->first();
                $record["client_id"] = $s->converted_id;
                }
            }

            if(isset($record["machine_id"])){
                if($record["machine_id"] != null){
                    $s = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $record["machine_id"]],['table', 'machines']])->first();
                $record["machine_id"] = $s->converted_id;
                }
            }

            $alreadyConverted = DB::table("converted_synchs2")->where([['sync_id', $syncId],['table', $table]])->count();

            if($alreadyConverted){
                $convertedID = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $syncId],['table', $table]])->first();
                $id = $convertedID->converted_id;

                DB::table($table)->where('id', $id)
                ->update($record);

                DB::table("sync_records2")->where([
                    ["name", $table],
                    ["data_id", $id],
                    ["staff_id", "!=", $staff_id]
                ])->delete();

                array_push($recordIds, array("table" => $table, "id" => $syncId));
            }else{
                $id = DB::table($table)->insertGetId($record);
                DB::table('converted_synchs2')->insert(
                    ['table' => $table, 'sync_id' => $syncId, 'converted_id' => $id]
                );
                array_push($recordIds, array("table" => $table, "id" => $syncId));

                //\App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id]);
                DB::table("sync_records2")->updateOrInsert(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id], ['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id]);
            }
            
            foreach($relationalTables AS $relationalTable){
                $relationalTableName = $relationalTable["table"];
                $relationalCol = $relationalTable["relationalCol"];

                DB::table($relationalTableName)->where($relationalTable["relationalCol"], $id)->delete();
                
                foreach($relationalTable["data"] AS $relationalData){
                    $relationalSyncId = $relationalData["id"];
                    $relationalData[$relationalCol] = $id;

                    unset($relationalData["id"]);
                    unset($relationalData["sync"]);

                    /* $alreadyConverted = DB::table("converted_synchs2")->where([['sync_id', $relationalSyncId],['table', $relationalTableName]])->count();

                    if($alreadyConverted){
                        $convertedID = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $relationalSyncId],['table', $relationalTableName]])->first();
                        $conId = $convertedID->converted_id;
        
                        DB::table($relationalTableName)->where('id', $conId)
                        ->update($relationalData);
        
                        DB::table("sync_records2")->where([
                            ["name", $relationalTableName],
                            ["data_id", $conId],
                            ["staff_id", "!=", $staff_id]
                        ])->delete();

                        array_push($recordIds, array("table" => $relationalTableName, "id" => $relationalSyncId));
                    }else{ */
                        $relId = DB::table($relationalTableName)->insertGetId($relationalData);
                        DB::table('converted_synchs2')->insert(
                            ['table' => $relationalTableName, 'sync_id' => $relationalSyncId, 'converted_id' => $relId]
                        );
                        array_push($recordIds, array("table" => $relationalTableName, "id" => $relationalSyncId));
                    /* } */

                    
                }
            }

            foreach($moduleTables AS $moduleTable){
                $moduleTableName = $moduleTable["table"];
                $moduleId = $moduleTable["module_id"];

                DB::table($moduleTableName)->where([["reference_id", $id], ["module_id", $moduleId]])->delete();

                foreach($moduleTable["data"] AS $moduleData){
                    $moduleSyncId = $moduleData["id"];
                    $moduleData["reference_id"] = $id;

                    unset($moduleData["id"]);
                    unset($moduleData["sync"]);

                    /* $alreadyConverted = DB::table("converted_synchs2")->where([['sync_id', $moduleSyncId],['table', $moduleTableName]])->count();

                    if($alreadyConverted){
                        $convertedID = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $moduleSyncId],['table', $moduleTableName]])->first();
                        $conId = $convertedID->converted_id;
        
                        DB::table($moduleTableName)->where('id', $conId)
                        ->update($moduleData);
        
                        DB::table("sync_records2")->where([
                            ["name", $moduleTableName],
                            ["data_id", $conId],
                            ["staff_id", "!=", $staff_id]
                        ])->delete();
                        array_push($recordIds, array("table" => $moduleTableName, "id" => $moduleSyncId));
                    }else{ */
                        $relId = DB::table($moduleTableName)->insertGetId($moduleData);
                        DB::table('converted_synchs2')->insert(
                            ['table' => $moduleTableName, 'sync_id' => $moduleSyncId, 'converted_id' => $relId]
                        );
                        array_push($recordIds, array("table" => $moduleTableName, "id" => $moduleSyncId));
                    /* } */
                }
            }

        }

        return $this->sendResponse($recordIds, 'records retrieved successfully.');
    }

    public function syncPush(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $record = $data["record"];
        $staff_id = (int) $data["staff_id"];
        
        foreach($record AS $key => $value){
            if($key == "created_date"){
                if (strpos($value, 'Z') !== false) {
                    $record['created_date'] = substr($value, 0, -4); //
                }
            }
            if($key == "dealer_id"){
                $fd = DB::table("converted_synchs")->where([['sync_id', $record['dealer_id']],['table', 'staffs']])->count();
                if($fd){
                    $fd2 = DB::table("converted_synchs")->select('converted_id', 'id')->where([['sync_id', $record['dealer_id']],['table', 'staffs']])->first();
                    $record['dealer_id'] = (int) $fd2->converted_id;
                }
            }

            if($key == "payee"){
                $fd = DB::table("converted_synchs")->where([['sync_id', $record['payee']],['table', 'staffs']])->count();
                if($fd){
                    $fd2 = DB::table("converted_synchs")->select('converted_id', 'id')->where([['sync_id', $record['payee']],['table', 'staffs']])->first();
                    $record['payee'] = (int) $fd2->converted_id;
                }
            }
        }

        $syncId = $record["id"];

        $s1 = DB::table($table)->where('id', $syncId)->count();
        $s2 = DB::table("converted_synchs")->where([['sync_id', $syncId],['table', $table]])->count();

        if($s1){ // Original Data OR Converted Data
            //updates
            $id = (int) $record["id"];
            unset($record["id"]);
            unset($record["sync"]);

            DB::table($table)->where('id', $id)
                ->update($record);

            DB::table("sync_records")->where([
                ["name", $table],
                ["data_id", $id],
                ["staff_id", "!=", $staff_id]
            ])->delete();
        }else if($s2){ //Error
            //updates
            $r = DB::table("converted_synchs")->select('converted_id', 'id')->where('sync_id', $syncId)->first();
            $id = (int) $r->converted_id;
            unset($record["id"]);
            unset($record["sync"]);

            DB::table($table)->where('id', $id)
                ->update($record);

            DB::table("sync_records")->where([
                ["name", $table],
                ["data_id", $id],
                ["staff_id", "!=", $staff_id]
            ])->delete();
        }else{
            unset($record["id"]);
            unset($record["sync"]);
            $id = DB::table($table)->insertGetId($record);
            DB::table('converted_synchs')->insert(
                ['table' => $table, 'sync_id' => $syncId, 'converted_id' => $id]
            );

            \App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id]);
        }
        return $this->sendResponse($id, 'records retrieved successfully.');
    }

    public function syncDelete(Request $request){
        $data = $request->all();
        $records = $data["records"];
        $staff_id = (int) $data["staff_id"];
        
        foreach($records AS $key => $value){
            DB::table($value["table_name"])->where('id', $value["reference_id"])->delete();
            \App\SyncDeleteRecord::firstOrCreate(['name' => $value["table_name"], 'staff_id' => $staff_id, 'data_id' => $value["reference_id"]]);
        }

        $r = DB::table("sync_delete_records")->select("name", "data_id")->whereExists(function ($query) use ($staff_id) {
            $query->select(DB::raw(1))
                  ->from('sync_records')
                  ->whereRaw("sync_records.name = sync_delete_records.name AND sync_records.staff_id != sync_delete_records.staff_id AND sync_delete_records.data_id = sync_records.data_id");
        })->get();



        return $this->sendResponse($r->toArray(), 'records retrieved successfully.');
    }

    //Server Firebase
    public function accessByEmail(Request $request){
        $data = $request->all();
        $email = $data["email"];

        $records = DB::table("staffs")->select("staffs.*", 'depots.name AS depot_name', 'roles.display_name AS role_name')->join('roles', 'staffs.role_id', '=', 'roles.id')->join('depots', 'staffs.depot_id', '=', 'depots.id')->where("staffs.email", $email)->first();

        return $this->sendResponse($records, 'Depot retrieved successfully.');
    }

    public function updateFCMToken(Request $request){
        $data = $request->all();
        $token = $data["token"];
        $id = $data["id"];

        $records = DB::table('staffs')
        ->where('id', $id)
        ->update(['fcm_token' => $token]);

        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'key=AAAAnwpjJ4A:APA91bGhQX_UvzPgzRYhoiowbvBzgSdftHXEB7niQqa0QmY-exWBE61eSNFIZ5SQBQfMqqF21LaGtSBxU7HFtUUX9QYeq1pyXoreHShhmeuAmelFkyFUnYg4JvkLdhvUYrccXKfPDxZF'
            ]
        ]);

        $response = $client->post('https://iid.googleapis.com/iid/v1/'.$token.'/rel/topics/general');

        return $this->sendResponse($records, 'Token Updated');
    }

    /* Stat */

    //Top 10 dealers
    public function topTenDealers(Request $request){
        $data = $request->all();
        $thisMonth = $data["thisMonth"];
        $lastMonth = $data["lastMonth"];

        $records = DB::table('staffs AS s')->select("s.name", "depots.name AS depot_name", "s.thumbnail",
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS mySold"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $lastMonth[1] AND YEAR(d.created_date) = $lastMonth[0]), 0) AS last_sold"),
        DB::raw("(SELECT d.created_date FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0] ORDER BY i.id DESC LIMIT 1) AS last_updated")
        )->join('depots', 's.depot_id', '=', 'depots.id')->whereRaw('s.role_id = 3')->having('mySold', '!=', 0)->orderBy('mySold', 'desc')->limit(10)->get();
        return $this->sendResponse($records, 'Top 10 Dealers retrieved successfully.');
    }

    //Top 10 Depot

    public function topTenDepot(Request $request){
        $data = $request->all();
        $thisMonth = $data["thisMonth"];
        $lastMonth = $data["lastMonth"];
        $records = DB::table('staffs AS s')->select("de.name AS depot_name", 
        DB::raw("SUM(IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0)) AS mySold"),
        DB::raw("SUM(IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $lastMonth[1] AND YEAR(d.created_date) = $lastMonth[0]), 0)) AS last_sold"),
        DB::raw("(SELECT d.created_date FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.depot_id = de.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0] ORDER BY i.id DESC LIMIT 1) AS last_updated")
        )->join('depots AS de', 's.depot_id', '=', 'de.id')->whereRaw('s.role_id = 3')->groupBy('de.id')->having('mySold', '!=', 0)->orderBy('mySold', 'desc')->limit(10)->get();
        return $this->sendResponse($records, 'Top 10 Depot retrieved successfully.');
    }

    public function depotDashboardDealers(Request $request){
        $data = $request->all();
        $thisMonth = $data["thisMonth"];
        $lastMonth = $data["lastMonth"];
        $depot_id = $data["depot_id"];
        $records = DB::table('staffs AS s')->select("s.name", "depots.name AS depot_name", "s.thumbnail", "s.id",
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS powder_thisMonth"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $lastMonth[1] AND YEAR(d.created_date) = $lastMonth[0]), 0) AS powder_lastMonth"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.id = 3 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS cup_thisMonth"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.id = 7 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS machine_thisMonth"),
        DB::raw("(SELECT d.created_date FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0] ORDER BY i.id DESC LIMIT 1) AS last_updated")
        )->join('depots', 's.depot_id', '=', 'depots.id')->whereRaw('s.role_id = 3 AND depots.id = ' . $depot_id)->orderBy('powder_thisMonth', 'desc')->get();
        return $this->sendResponse($records, 'depotDashboardDealers');
    }
    
    //Total Clients
    public function depotTotalClients(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $records = DB::table('clients AS c')
        ->join('staffs AS s', 's.id', '=', 'c.staff_id')->whereRaw('s.depot_id = ' . $depot_id)->count();
        return $this->sendResponse($records, 'depotTotalClients');
    }

    //Total Machines
    public function depotTotalMachines(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $records = DB::table('machines AS m')
        ->join('staffs AS s', 's.id', '=', 'm.staff_id')->whereRaw('s.depot_id = ' . $depot_id)->count();
        return $this->sendResponse($records, 'depotTotalMachines');
    }

    public function dealerFullInfo(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];

        $record = DB::table("staffs AS s")
        ->select("s.*", "d.name AS depot_name",
        DB::raw("IFNULL((SELECT COUNT(*) FROM machines WHERE staff_id = $staff_id), 0) AS total_machines"),
        DB::raw("IFNULL((SELECT COUNT(*) FROM clients WHERE staff_id = $staff_id), 0) AS total_clients"),
        DB::raw("(SELECT created_at FROM callsheets WHERE staff_id = s.id ORDER BY id DESC LIMIT 1) AS last_transaction")
        )
        ->join('depots AS d', 'd.id', '=', 's.depot_id')
        ->where("s.id", $staff_id)->first();
        return $this->sendResponse($record, 'dealerFullInfo');
    }

    public function depotClientsMonthInc(Request $request){
        $data = $request->all();
        $year = $data["year"];
        $depot_id = $data["depot_id"];
        $monthData = [];
        $until = Carbon::parse("next month")->format("F Y");

        foreach($this->months AS $month){
            if("$month $year" != $until){
                $m = Carbon::parse("$month $year")->subMonth()->endOfMonth()->toDateTimeString();
                $r = DB::table('machines AS m')->whereDate("m.created_at", "<=", $m)
                ->join('staffs AS s', 's.id', '=', 'm.staff_id')->whereRaw('s.depot_id = ' . $depot_id)
                ->count();
                array_push($monthData, ["month" => Carbon::parse("$month")->format("M"), "count" => $r, "test" => $until]);
            }else{
                break;
            }
            
        }

        return $this->sendResponse($monthData, 'depotClientsMonthInc');
    }

    public function machinesNearby(Request $request){
        $data = $request->all();
        $lat = $data["lat"];
        $lng = $data["lng"];
        $distance = isset($data["distance"]) ? $data["distance"] : 1;

        $records = DB::table("machines AS m")->select("m.lat", "m.lng", "m.id", 
        DB::raw("((ACOS(SIN($lat * PI() / 180) * SIN(m.lat * PI() / 180) + COS($lat * PI() / 180) * COS(m.lat * PI() / 180) * COS(($lng - m.lng) * PI() / 180)) * 180 / PI()) * 60 * 1.1515) as distance"))
        ->having('distance', '<=', $distance)
        ->orderBy('distance', 'asc')->get();

        return $this->sendResponse($records, 'machinesNearby');
    }

    public function paypalPay2(Request $request){
        $data = $request->all();

        DB::table('log_tests')->insert(
            ['message' => json_encode($data)]
        );

        if($data["payment_status"] === "Completed"){
            $mytime = Carbon::now();
            

            $qty = (int) $data["quantity"];

            $codes = "";
            $days = 0;

            $isTrue = false;
            $string = "";

            for ($x = 0; $x < $qty; $x++) {
                do {
                    $string = str_random(6);
                    $isExist = DB::table("payment_codes")->where('code', $string)->count();

                    if($data["option_selection1"] == "1 Month")
                        $days = 30;
                    if($data["option_selection1"] == "3 Months")
                        $days = 90;
                    if($data["option_selection1"] == "6 Months")
                        $days = 180;
                    if($data["option_selection1"] == "1 Year")
                        $days = 360;
                    

                    if($isExist){
                        $isTrue = true;
                    }
                    else{
                        $isTrue = false;
                        DB::table('payment_codes')->insert(
                            ['code' => $string, "days" => $days]
                        );
                    }
                } while ($isTrue);

                $codes .= "<div><strong>" . $string . "</strong></div>";
            }

            $to_name = $data["first_name"] . " " . $data["last_name"];
            $to_email = $data["payer_email"];

            $rec = array(
                "name" => $data["first_name"], 
                "body" => "<div>Here's your <strong>Payment Code</strong> and will expire <strong>" . $data["option_selection1"] . "</strong> after use:</div>" . $codes . "<br/>",
                "expiration" => $data["option_selection1"]
            );

            Mail::send('emails.mail', $rec, function($message) use ($to_name, $to_email) {
                $message->to($to_email, $to_name)->subject('New Payment Codes');
                $message->from("techsupport@sugbu.me", "Dealer-app Payment Codes");

            });
        }
   
        return $this->sendResponse($data, 'paypalPay2');
    }

    public function serverLogin(Request $request){
        $data = $request->all();
        $username = $data["username"];
        $password = $data["password"];
        $apiAccess = "";
        $userData = "";

        $record = DB::table("staffs")->where([['username', $username], ['passcode', $password]])->count();;

        if($record){
            //Refresh Token
            //def5020010b14c290fc3232d536eecd50aded90a7b357c7c1aeb6dc5489009691dd795211fd2ddefe7dfe7796495a1533070fbbda47820e0d99432b545bae1c33d8047d45a28ef8b2098f8e70a9f37bd17d074d57ec235a56bb34afd97e097e43da9b3f3c32e42e92d57b58f71bec5f3aca038aa4fa2087ddc09bfdd91f729ced0ae5e7959edf351e986d865f6ac21806de7590600c92beffa7231f125c6709a67d34d0a2cea9e6f6031d4c92dab5216737547517bd3bcae2a1f357e06686e202611a7cfddca296eac41a11f1d5bffc74711393e4edb7bf3e93d376bb826e6c0301d0d65d2c8089bc8a4b01b701e73f1b50ba16710c6618cc23bf4943419afe0bfb767ed46c5de50a51adcf7a3d7f4969d2769e270c29d832d3f6c127f8458e41bda242b9370d416048f402f6b17991897d82e77e79c51531e4019b90fa597e24a45f84e674b84a877cb2a6dce694d901e3a6ab3eb31276612755494beb0e4350d7177cd
            $apiAccess = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjI2OGRjN2Y0ZjZmMGE4ZDE0NzA1ZDk1ODAzMjA0Nzk0ODIyYWI0NTY1YmU3NzUyN2UxOTEwNzM2ODZmNzNjMDZmZTY2Y2FmM2QyYzI1MmY1In0.eyJhdWQiOiI0IiwianRpIjoiMjY4ZGM3ZjRmNmYwYThkMTQ3MDVkOTU4MDMyMDQ3OTQ4MjJhYjQ1NjViZTc3NTI3ZTE5MTA3MzY4NmY3M2MwNmZlNjZjYWYzZDJjMjUyZjUiLCJpYXQiOjE1NzE4MTU3NzksIm5iZiI6MTU3MTgxNTc3OSwiZXhwIjoxNjAzNDM4MTc5LCJzdWIiOiIxIiwic2NvcGVzIjpbIioiXX0.m0IR4Ypq8WYi2R1PKoJ4EIVFyM5zDg84mJu1Z05y6TswV8vQjVY02ZP-Pzx1WC0cOBi7SeBic__fWyFA6yb_mu3gf5ILNdI8Bv4czd9NV66Fqi0_SR6sFUO4cmGujxJKljWGsZgCRkvz8igZY9VdOgkMkuJcnUfIPqyOpKlBFaSkcU831i1Hd2Xo7zCtJ5WEpphuJpw52gjMkQ1SbUHT0eTz4SVQp_7Ln-YX2YDJ2DmcQ-mf3Q7Vnx9xFyFC6AtgL-Nhxzx8vf6JaVhn51X0G4vSGRlG0_DSPSTP_T6TP8ZGAzB_C93125qUl6HoVIIwx7JlhAAgwLuZoQEgaozWX0VKiML8iZWdQJLAT9K8aDf83mPUYLPXqwqhk3rQAuSE76oKH6D4ZQgkKnVJOcQr2P8QbgWZOzCOsaJ8EDlz_2anbJ-sFP6xuDmHZk5twbM9N_1dxgThaGrrXjCBFLgHCmzOfbiB8kFY_aL-GTRYcy6pdR9te-TPNxcMh4HFzxmQcCzNyuirBk1a8SlHZDdYh-t8oi-LXUXi9oUBua6eMTEVP8Ck_jtcfzxZjRgXNPtiPlFXvpwpzMr3vDDWswp6y_zEjuNLOPEADCtwomovE0_2vAHKH3zo3fIu8k7nK9Q7OV_zBZhyuc7NhPjPS3fM2KhrHET5Xbvgo-nfOPMQjiM";
            
            $userData = DB::table('staffs AS s')->select('s.*', 'r.display_name AS role_name')->where([['username', $username], ['passcode', $password]])
            ->join('roles AS r', 's.role_id', '=', 'r.id')
            ->first();

            $resizedThumbnail = Image::make($userData->thumbnail);
            $resizedThumbnail->resize(100, 100);

            $userData->thumbnail = (string) $resizedThumbnail->encode('data-url');;

            if(!$data["inDevelopment"]){
                OneSignal::sendNotificationUsingTags(
                    $userData->name . " logged in to the server",
                    array(
                        ["field" => "tag", "key" => "userId", "relation" => "=", "value" => 55]
                    ),
                    $url = null,
                    $data = null,
                    $buttons = null,
                    $schedule = null
                );
            }
            


            //$userData = DB::table("staffs")->where([['username', $username], ['passcode', $password]])->first();
            
        }
            
       
        return $this->sendResponse(array("withAccess" => $record, "apiAccess" => $apiAccess, "userData" => $userData), 'serverLogin22');
    }

    public function resetPassword(Request $request)
    {
        $data = $request->all();
        $username = $data["username"];
        $password = $data["password"];
        $key = $data["key"];
        $keyCorrect = false;

        $isExist = DB::table("staffs")->where('username', $username)->count();
        if($isExist){
            $depotId = DB::table("staffs")->select("depot_id", "id")->where('username', $username)->first();
            $hashed = DB::table("depots")->select("key")->where('id', $depotId->depot_id)->first();
            if (Hash::check($key, $hashed->key)) {
                $keyCorrect = true;
                DB::table("staffs")->where('id', $depotId->id)->update(['passcode' => $password]);
            }
        }

        return $this->sendResponse(array("userExist" => $isExist, "keyCorrect" => $keyCorrect), 'resetPassword2');
    }


    //Temp
    public function importOldServer(){
        
        DB::table("machines_copy")->whereNotNull('owner_id')->orderBy('id')->chunk(100, function ($machines) {
            foreach($machines AS $key => $value){
                $client = DB::table("clients_copy")->where("id", $value->owner_id)->first();
                $staff = DB::table("staffs")->where("mapper_id", $value->creator_id)->first();
                
                //client thumbnail
                
                if(is_object($client)){
                    if($client->photo !== null || $client->photo !== ""){
                        try {
                            $ct = Image::make($value->photo);
                            $ct->resize(150, null, function ($constraint) {
                                $constraint->aspectRatio();
                            });
    
                            $client_thumbnail = (string) $ct->encode('data-url');
                        }
                        catch (\Exception $e) {
                            $client_thumbnail = null;
                        }
                    }else
                        $client_thumbnail = null;
                    
    
                    $client_id = DB::table('clients')->insertGetId(
                        [
                            'name' => $client->name, 
                            'alias' => ($client->alias) ? $client->alias : null,
                            'company' => ($client->company) ? $client->company : null,
                            'email' => ($client->email) ? $client->email : null,
                            'photo' => ($client->photo) ? $client->photo : null,
                            'thumbnail' => $client_thumbnail,
                            'lat' => null,
                            'lng' => null,
                            'created_at' => date("Y-m-d H:i:s", $client->created_date / 1000),
                            'updated_at' => date("Y-m-d H:i:s", $client->updated_date / 1000),
                            'deleted_at' => null,
                            'staff_id' => $staff->id,
                            'location_id' => null,
                        ]
                    );
    
                    if($client->contact){
                        DB::table('contacts')->insert(
                            [
                                'reference_id' => $client_id,
                                'contact' => $client->contact,
                                'module_id' => 3
                            ]
                        );
                    }
    
                    if($client->contact2){
                        DB::table('contacts')->insert(
                            [
                                'reference_id' => $client_id,
                                'contact' => $client->contact2,
                                'module_id' => 3
                            ]
                        );
                    }
                }else{
                    $client_id = null;
                }

                $lat = $value->lat;
                $lng = $value->lng;
                $location = DB::table("locations")->select("id", "name_1", "name_2", "name_3")->whereRaw("st_contains(SHAPE, ST_GeomFromText('POINT($lng $lat)', 1))", [])->first();
                
                if(!is_object($location)){
                    $loc = null;
                }else{
                    $loc = $location->id;
                    // echo "$lat $lng";// , $location->name_1 + " " + $location->name_2 + " " + $location->name_3 + "<br/>";
                    // echo " " . $location->name_1 . " " . $location->name_2 . " " . $location->name_3 . " $staff->id $created_at <br/>";
                }

                if(!is_object($value)){
                    $thumbnail = null;
                }else{
                    if($value->photo !== null || $value->photo !== ""){
                        try {
                            $img = Image::make($value->photo);
                            $img->resize(150, null, function ($constraint) {
                                $constraint->aspectRatio();
                            });
    
                            $thumbnail = (string) $img->encode('data-url');
                        }
                        catch (\Exception $e) {
                            $thumbnail = null;
                        }
                        
                    }else
                        $thumbnail = null;
                }

                $machineId = DB::table('machines')->insertGetId(
                    [
                        'staff_id' => $staff->id,
                        'client_id' => $client_id,
                        'created_at' => date("Y-m-d H:i:s", $value->created_date / 1000),
                        'updated_at' => date("Y-m-d H:i:s", $value->updated_date / 1000),
                        'location_id' => $loc,
                        'accuracy' => $value->accuracy,
                        'delivery' => ($value->delivery === "Unspecified") ? "Sat" : $value->delivery,
                        'lat' => $lat,
                        'lng' => $lng,
                        'machine_type' => ($value->machine_type == "Unspecified") ? "Other" : $value->machine_type,
                        'sequence' => $value->sequence,
                        'photo' => ($value->photo) ? $value->photo : null,
                        'thumbnail' => $thumbnail,
                        'establishment_type' => ($value->establishment_type) ? $value->establishment_type : null,
                        'deleted_at' => null

                    ]
                );
                echo $machineId . "<br/>";
            }
        });
        
    }

    public function fixNoLocations(){
        //Fixes machines with no locations
        $noLocs = DB::table("machines")->whereRaw('lat IS NOT NULL AND region IS NULL')->get();

        foreach($noLocs AS $noLoc){
            $loc = DB::table("locations")->select(DB::raw("region, province, name_2 AS municipal, name_3 AS brgy"))->whereRaw("MbrWithin(GeomFromText(?), shape)", ['POINT('.$noLoc->lng.' '.$noLoc->lat.')'])->first();
            if($loc){
                DB::table('machines')
            ->where('id', $noLoc->id)
            ->update(['region' => $loc->region, 'province' => $loc->province, 'municipal' => $loc->municipal, 'brgy' => $loc->brgy]);
            }
            
        }
        return $this->sendResponse($noLoc, 'loc retrieved successfully.');
    }
}
