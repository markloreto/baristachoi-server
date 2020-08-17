<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Support\Facades\Hash;
use DB;
use Validator;
use Intervention\Image\ImageManagerStatic as Image;
use App\Services\PayUService\Exception;
use GuzzleHttp\Client;
use Carbon\Carbon;
use File;
use Response;

class BasicController extends Controller
{
    //
    public function botPhotoGallery($id){
        $mainPhoto = DB::table("pabile_product_photos")->select("photo")->where([["primary", 1], ["product_id", $id]])->first();
        $p = DB::table("pabile_products")->select("price", "previous_price")->where("id", $id)->first();
        $photo = str_replace("pabile/", "", $mainPhoto->photo);

        $path = storage_path("app/pabile/" . $photo);
        $maskPath = storage_path("app/public/mask.png");
        $pesoPath = storage_path("app/public/peso.png");
        

        $img = Image::make($path);
        $img->resize(500, 500);
        $w = $img->width();
        $h = $img->height();

        $watermark = Image::make($maskPath);     
        $watermark->resize($w, $h);
        $img->insert($watermark);

        $peso = Image::make($pesoPath);
        $img->insert($pesoPath, "top-left", 4, 10);

        $img->text($p->price, 58, 60, function($font) {
            $fontPath = storage_path("app/public/Roboto-Bold.ttf");
            $font->size(38);
            $font->color("#464646");
            $font->file($fontPath);
        });

        $img->text($p->price, 56, 58, function($font) {
            $fontPath = storage_path("app/public/Roboto-Bold.ttf");
            $font->size(38);
            $font->color("#ffffff");
            $font->file($fontPath);
        });

        if($p->previous_price){
            $smallPesoPath = storage_path("app/public/small_peso.png");
            $smallPeso = Image::make($smallPesoPath);
            $img->insert($smallPesoPath, "top-left", 4, 50);
        }
 
        return $img->response();
    }

    public function profilePhoto($id){
        $staffs = DB::table("staffs")->where('id', $id)->first();
        $img = Image::make($staffs->photo);
        return $img->response('jpg', 70);
        //print_r($img);
    }
    public function attachmentView($id){
        $attachment = DB::table("attachments")->where('id', $id)->first();
        $img = Image::make($attachment->b64);
        return $img->response('jpg', 70);
        //print_r($img);
    }

    public function generatePaymentCode($days){
        $mytime = Carbon::now();
        $string = str_random(6);
        $isExist = DB::table("payment_codes")->where('code', $string)->count();
        if($isExist){
            $string = "";
        }
        else{
            DB::table('payment_codes')->insert(
                ['code' => $string, "days" => $days]
            );
        }
        return response()->json(["code" => $string, "days" => $days, "generate_date" =>  $mytime->toDateTimeString()]);
    }

    public function abc($depot_id, $year, $month){
        $date = Carbon::parse("$year-$month");
        $depot_id = $depot_id;
        $product_categories = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
        $lastMonthWarehouse = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
        foreach($product_categories AS $key => $record){
            $d = DB::table("products AS p")
            ->select("p.id AS product_id", "p.name AS product_name", "p.cost AS product_cost", "p.price AS product_price", "mu.name AS unit_name", "mu.abbr AS abbr",
            DB::raw("(ifnull((SELECT SUM(qty) FROM inventories i JOIN inventory_adjustments d ON d.id = i.reference_id WHERE type = 2 AND product_id = p.id AND module_id = 4 AND DATE(created_date) < '".$date."' AND d.depot_id = ".$depot_id."), 0)) AS adjustment_minus"),
            DB::raw("(ifnull((SELECT SUM(qty) FROM inventories i JOIN inventory_adjustments d ON d.id = i.reference_id WHERE type = 1 AND product_id = p.id AND module_id = 4 AND DATE(created_date) < '".$date."' AND d.depot_id = ".$depot_id."), 0)) AS adjustment_plus"),
            DB::raw("(ifnull((SELECT SUM(qty) FROM inventories i JOIN delivery_receipts d ON d.id = i.reference_id WHERE type = 1 AND product_id = p.id AND module_id = 1 AND DATE(created_date) < '".$date."' AND d.depot_id = ".$depot_id."), 0)) AS delivery_receipt"),
            DB::raw("(ifnull((SELECT SUM(qty) FROM inventories i JOIN disrs d ON d.id = i.reference_id WHERE type = 2 AND product_id = p.id AND module_id = 2 AND DATE(created_date) < '".$date."' AND d.depot_id = ".$depot_id."), 0)) AS disr"))
            ->join('product_categories AS pc', 'p.category', '=', 'pc.id')
            ->join('measurement_units AS mu', 'p.measurement_unit', '=', 'mu.id')->whereRaw('p.star = 1')
            ->where("p.category", $record->id)
            ->orderBy('p.sequence', 'asc')
            ->get()
            ->toArray();

            $warehouseTotal = 0;
            foreach($d AS $value){
                $warehouseTotal += intval($value->adjustment_plus) + (($value->delivery_receipt - $value->disr) - intval($value->adjustment_minus));
            }


            $lastMonthWarehouse[$key]->warehouseTotal = $warehouseTotal;
            $lastMonthWarehouse[$key]->products = $d;
        }

        $data["warehouse"] = $lastMonthWarehouse;

        $dealers = DB::table('staffs AS s')->select("id", "name", 
        DB::raw("(SELECT id FROM disrs WHERE depot_id = ".$depot_id." AND dealer_id = s.id AND DATE(created_date) < '".$date."' ORDER BY sequence DESC LIMIT 1) AS last_disr"))
        ->whereRaw("s.depot_id = :depot_id AND s.role_id = 3", ["depot_id" => $depot_id])
        ->havingRaw("last_disr IS NOT NULL")
        ->get()
        ->toArray();

        foreach($dealers AS $dealerKey => $dealersRecord){
            $lastMonthDUV = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
            $lastMonthDUVTotal = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
            foreach($product_categories AS $key => $record){
                $d = DB::table("products AS p")
                ->select("p.id AS product_id", "p.name AS product_name", "p.cost AS product_cost", "p.price AS product_price", "mu.name AS unit_name", "mu.abbr AS abbr",
                DB::raw("IFNULL((SELECT IFNULL(remaining, 0) FROM inventories i WHERE reference_id = " . $dealersRecord->last_disr . " AND type = 2 AND product_id = p.id AND module_id = 2 AND depot_id = ".$depot_id."), 0) AS remaining_stock"))
                ->join('product_categories AS pc', 'p.category', '=', 'pc.id')
                ->join('measurement_units AS mu', 'p.measurement_unit', '=', 'mu.id')->whereRaw('p.star = 1')
                ->where("p.category", $record->id)
                ->orderBy('p.sequence', 'asc')
                ->get()
                ->toArray();

                $duvTotal = 0;
                foreach($d AS $k => $value){
                    $duvTotal += $value->remaining_stock;
                }

                $lastMonthDUV[$key]->duvTotal = $duvTotal;
                $lastMonthDUV[$key]->products = $d;

            }
            $dealers[$dealerKey]->category = $lastMonthDUV;
        }

        $data["dealers"] = $dealers;


        $lastMonthDUVTotal = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
        $disrArray = [];
        foreach($dealers AS $k => $dealer){
            array_push($disrArray, $dealer->last_disr);
        }

        if(count($disrArray) == 0){
            array_push($disrArray, 0);
        }

        
        foreach($product_categories AS $key => $record){
            $d = DB::table("products AS p")
            ->select("p.id AS product_id", "p.name AS product_name", "p.cost AS product_cost", "p.price AS product_price", "mu.name AS unit_name", "mu.abbr AS abbr",
            DB::raw("IFNULL((SELECT SUM(IFNULL(remaining, 0)) FROM inventories i WHERE depot_id = ".$depot_id." AND type = 2 AND product_id = p.id AND module_id = 2 AND i.reference_id IN (" . implode(",", $disrArray) . ")), 0) AS remaining_stock"))
            ->join('product_categories AS pc', 'p.category', '=', 'pc.id')
            ->join('measurement_units AS mu', 'p.measurement_unit', '=', 'mu.id')->whereRaw('p.star = 1')
            ->where("p.category", $record->id)
            ->orderBy('p.sequence', 'asc')
            ->get()
            ->toArray();

            $duvTotal = 0;
            foreach($d AS $k => $value){
                $duvTotal += $value->remaining_stock;
            }

            $lastMonthDUVTotal[$key]->duvTotal = $duvTotal;
            $lastMonthDUVTotal[$key]->products = $d;

        }

        $data["duvTotal"] = $lastMonthDUVTotal;

        $deliveryReceipts = DB::table('delivery_receipts AS d')->select("id", "dr AS dr_num", "created_date",
        DB::raw("IFNULL((SELECT SUM(IFNULL(amount, 0)) FROM payments p WHERE reference_id = d.id AND module_id = 1 AND depot_id = ".$depot_id."), 0) AS total_payment"),
        DB::raw("IFNULL((SELECT SUM(IFNULL(qty, 0) * cost) FROM inventories i WHERE reference_id = d.id AND module_id = 1 AND depot_id = ".$depot_id."), 0) AS total_cost")) 
        ->whereRaw("d.depot_id = ? AND DATE(created_date) >= ? AND DATE(created_date) <= ?", [$depot_id, $date, Carbon::parse("$year-$month")->endOfMonth()])
        ->get()
        ->toArray();

        foreach($deliveryReceipts AS $deliveryReceiptsKey => $deliveryReceiptsRecords){
            $dr = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
            foreach($product_categories AS $key => $record){
                $d = DB::table("products AS p")
                ->select("p.id AS product_id", "p.name AS product_name", "p.cost AS product_cost", "p.price AS product_price", "mu.name AS unit_name", "mu.abbr AS abbr",
                DB::raw("(SELECT (IFNULL(qty, 0) * cost) FROM inventories i WHERE reference_id = " . $deliveryReceiptsRecords->id . " AND type = 1 AND product_id = p.id AND module_id = 1 AND depot_id = ".$depot_id.") AS cost"),
                DB::raw("(SELECT IFNULL(qty, 0) FROM inventories i WHERE reference_id = " . $deliveryReceiptsRecords->id . " AND type = 1 AND product_id = p.id AND module_id = 1 AND depot_id = ".$depot_id.") AS received"))
                ->join('product_categories AS pc', 'p.category', '=', 'pc.id')
                ->join('measurement_units AS mu', 'p.measurement_unit', '=', 'mu.id')->whereRaw('p.star = 1')
                ->where("p.category", $record->id)
                ->orderBy('p.sequence', 'asc')
                ->get()
                ->toArray();

                $total = 0;
                $cost = 0;
                foreach($d AS $k => $value){
                    $total += $value->received;
                    $cost += $value->cost;
                }

                $dr[$key]->total = $total;
                $dr[$key]->cost = $cost;
                
                $dr[$key]->products = $d;
            }
            $deliveryReceipts[$deliveryReceiptsKey]->categories= $dr;
        }

        $data["delivery_receipts"] = $deliveryReceipts;

        $deliveryReceiptsTotal = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
        $drArray = [];
        foreach($deliveryReceipts AS $k => $dr){
            array_push($drArray, $dr->id);
        }

        if(count($drArray) == 0){
            array_push($drArray, 0);
        }

        
        foreach($product_categories AS $key => $record){
            $d = DB::table("products AS p")
            ->select("p.id AS product_id", "p.name AS product_name", "p.cost AS product_cost", "p.price AS product_price", "mu.name AS unit_name", "mu.abbr AS abbr",
            DB::raw("IFNULL((SELECT SUM(IFNULL(qty, 0) * cost) FROM inventories i WHERE type = 1 AND product_id = p.id AND module_id = 1 AND i.reference_id IN (" . implode(",", $drArray) . ") AND depot_id = ".$depot_id."), 0) AS cost"),
            DB::raw("IFNULL((SELECT SUM(IFNULL(qty, 0)) FROM inventories i WHERE type = 1 AND product_id = p.id AND module_id = 1 AND i.reference_id IN (" . implode(",", $drArray) . ") AND depot_id = ".$depot_id."), 0) AS received"))
            ->join('product_categories AS pc', 'p.category', '=', 'pc.id')
            ->join('measurement_units AS mu', 'p.measurement_unit', '=', 'mu.id')->whereRaw('p.star = 1')
            ->where("p.category", $record->id)
            ->orderBy('p.sequence', 'asc')
            ->get()
            ->toArray();

            $total = 0;
            $cost = 0;
            foreach($d AS $k => $value){
                $total += $value->received;
                $cost += $value->cost;
            }

            $deliveryReceiptsTotal[$key]->total = $total;
            $deliveryReceiptsTotal[$key]->cost = $cost;
            $deliveryReceiptsTotal[$key]->products = $d;

        }

        $data["deliveryReceiptsTotal"] = $deliveryReceiptsTotal;

        $disrDealers = DB::table('staffs AS s')->select("id", "name",
        DB::raw("(SELECT SUM(IFNULL(sold, 0) * price) FROM inventories i WHERE type = 2 AND module_id = 2 AND reference_id IN (SELECT id FROM disrs WHERE dealer_id = s.id AND created_date > '".$date."' AND created_date < '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id.")) AS total_dealer_price"),
        DB::raw("IFNULL((SELECT SUM(IFNULL(amount, 0)) FROM payments p WHERE module_id = 2 AND reference_id IN (SELECT id FROM disrs WHERE dealer_id = s.id AND created_date > '".$date."' AND created_date < '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id.")), 0) AS total_dealer_payments"),
        DB::raw("(SELECT COUNT(id) FROM disrs WHERE dealer_id = s.id AND DATE(created_date) >= '".$date."' AND DATE(created_date) <= '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id." ORDER BY sequence DESC LIMIT 1) AS disr"))
        ->whereRaw("s.depot_id = :depot_id AND s.role_id = 3", ["depot_id" => $depot_id])
        ->havingRaw("disr > 0")
        ->get()
        ->toArray();

        foreach($disrDealers AS $k => $dealer){
            $disrs = DB::table('disrs AS d')->select("id", "created_date", "sequence",
            DB::raw("(SELECT SUM(IFNULL(sold, 0) * price) FROM inventories i WHERE type = 2 AND module_id = 2 AND reference_id = d.id AND depot_id = ".$depot_id.") AS total_price"),
            DB::raw("IFNULL((SELECT SUM(IFNULL(amount, 0)) FROM payments p WHERE module_id = 2 AND reference_id = d.id AND depot_id = ".$depot_id."), 0) AS total_payments"))
            ->whereRaw("dealer_id = ? AND depot_id = ? AND DATE(created_date) >= ? AND DATE(created_date) <= ?", [$dealer->id, $depot_id, $date, Carbon::parse("$year-$month")->endOfMonth()])
            ->orderBy('sequence', 'asc')
            ->get()
            ->toArray();

            foreach($disrs AS $key => $disr){
                $cats = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();
                foreach($product_categories AS $susi => $record){
                    $d = DB::table("products AS p")
                    ->select("p.id AS product_id", "p.name AS product_name", "p.cost AS product_cost", "p.price AS product_price", "mu.name AS unit_name", "mu.abbr AS abbr",
                    DB::raw("(ifnull((SELECT SUM(sold * price) FROM inventories i WHERE type = 2 AND product_id = p.id AND module_id = 2 AND reference_id = ".$disr->id." AND depot_id = ".$depot_id."), 0)) AS price"),
                    DB::raw("(ifnull((SELECT SUM(sold) FROM inventories i WHERE type = 2 AND product_id = p.id AND module_id = 2 AND reference_id = ".$disr->id." AND depot_id = ".$depot_id."), 0)) AS sold"))
                    ->join('product_categories AS pc', 'p.category', '=', 'pc.id')
                    ->join('measurement_units AS mu', 'p.measurement_unit', '=', 'mu.id')->whereRaw('p.star = 1')
                    ->where("p.category", $record->id)
                    ->orderBy('p.sequence', 'asc')
                    ->get()
                    ->toArray();

                    $total = 0;
                    $price = 0;
                    foreach($d AS $v){
                        $total += $v->sold;
                        $price += $v->price;
                    }

                    $cats[$susi]->total = $total;
                    $cats[$susi]->price = $price;
                    $cats[$susi]->products = $d;
                }
                $disrs[$key]->categories = $cats;
            }

            $disrDealers[$k]->disrs = $disrs;
        }

        $data["disrDealers"] = $disrDealers;

        $solds = DB::table('product_categories')->select("id", "name")->where("id", "!=", 4)->orderBy('sequence', 'asc')->get()->toArray();

        foreach($product_categories AS $key => $record){
            $d = DB::table("products AS p")
            ->select("p.id AS product_id", "p.name AS product_name", "p.cost AS product_cost", "p.price AS product_price", "mu.name AS unit_name", "mu.abbr AS abbr",
            DB::raw("IFNULL((SELECT SUM(IFNULL(sold, 0)) FROM inventories i WHERE i.product_id = p.id AND type = 2 AND module_id = 2 AND reference_id IN (SELECT id FROM disrs WHERE DATE(created_date) >= '".$date."' AND DATE(created_date) <= '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id.")), 0) AS total_sold"),
            DB::raw("IFNULL((SELECT SUM(IFNULL(sold, 0) * price) FROM inventories i WHERE i.product_id = p.id AND type = 2 AND module_id = 2 AND reference_id IN (SELECT id FROM disrs WHERE DATE(created_date) >= '".$date."' AND DATE(created_date) <= '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id.")), 0) AS total_price"),
            DB::raw("IFNULL((SELECT SUM(IFNULL(sold, 0) * cost) FROM inventories i WHERE i.product_id = p.id AND type = 2 AND module_id = 2 AND reference_id IN (SELECT id FROM disrs WHERE DATE(created_date) >= '".$date."' AND DATE(created_date) <= '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id.")), 0) AS total_cost"))
            ->join('product_categories AS pc', 'p.category', '=', 'pc.id')
            ->join('measurement_units AS mu', 'p.measurement_unit', '=', 'mu.id')->whereRaw('p.star = 1')
            ->where("p.category", $record->id)
            ->orderBy('p.sequence', 'asc')
            ->get()
            ->toArray();

            $total = 0;
            $price = 0;
            $cost = 0;
            foreach($d AS $k => $value){
                $total += $value->total_sold;
                $price += $value->total_price;
                $cost += $value->total_cost;
            }

            $solds[$key]->total = $total;
            $solds[$key]->price = $price;
            $solds[$key]->cost = $cost;
            $solds[$key]->products = $d;

        }

        $data["sold"] = $solds;

        $disrPayments = DB::table('payments AS p')->select(
        DB::raw("IFNULL(SUM(amount), 0) AS total_payment"))
        ->whereRaw("depot_id = ? AND module_id = 2 AND reference_id IN (SELECT id FROM disrs WHERE DATE(created_date) >= '".$date."' AND DATE(created_date) <= '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id.")", [$depot_id])
        ->first();

        $disrCost = DB::table('inventories AS i')->select(
            DB::raw("IFNULL(SUM(IFNULL(sold,0) * price), 0) AS total_price"))
            ->whereRaw("depot_id = ? AND type = 2 AND module_id = 2 AND reference_id IN (SELECT id FROM disrs WHERE DATE(created_date) >= '".$date."' AND DATE(created_date) <= '".Carbon::parse("$year-$month")->endOfMonth()."' AND depot_id = ".$depot_id.")", [$depot_id])
            ->first();

        $data["disrPayments"] = ["payment" => $disrPayments->total_payment, "amount" => $disrCost->total_price];

        $deliveryPayments = DB::table('payments AS p')->select(
        DB::raw("IFNULL(SUM(amount), 0) AS total_payment"))
        ->whereRaw("depot_id = ? AND module_id = 1 AND reference_id IN (SELECT id FROM delivery_receipts WHERE DATE(created_date) < '".$date."' AND depot_id = ".$depot_id.")", [$depot_id])
        ->first();

        $deliveryCost = DB::table('inventories AS i')->select(
            DB::raw("IFNULL(SUM(qty * cost), 0) AS total_cost"))
            ->whereRaw("depot_id = ? AND type = 1 AND module_id = 1 AND reference_id IN (SELECT id FROM delivery_receipts WHERE DATE(created_date) < '".$date."' AND depot_id = ".$depot_id.")", [$depot_id])
            ->first();

        $data["previous_deliveries"] = ["payment" => floatval($deliveryPayments->total_payment), "amount" => floatval($deliveryCost->total_cost)];
        
        /* echo "<pre>";
        print_r($data);
        echo "</pre>"; */
        return response()->json($data);
    }
}
