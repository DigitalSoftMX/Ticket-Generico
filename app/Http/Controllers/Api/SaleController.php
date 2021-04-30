<?php

namespace App\Http\Controllers\Api;

use App\Bomb;
use App\Client;
use App\Http\Controllers\Controller;
use App\RegisterTime;
use App\Repositories\Activities;
use App\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Schedule;
use App\User;
use Illuminate\Support\Facades\Validator;
use App\SharedBalance;
use App\Station;
use Exception;
use SimpleXMLElement;

class SaleController extends Controller
{
    private $activities, $user, $station;
    public function __construct(Activities $activities)
    {
        $this->activities = $activities;
        $this->user = Auth::user();
        if ($this->user != null && ($this->user->role_id == 4 || $this->user->role_id == 5)) {
            if ($this->user->role_id == 4) {
                $this->station = $this->user->dispatcher->station;
            }
        } else {
            $this->activities->logout(JWTAuth::getToken());
        }
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        switch ($this->user->role_id) {
            case 4:
                $validator = Validator::make($request->only('schedule_id'), ['schedule_id' => 'required|integer']);
                if ($validator->fails()) {
                    return $this->activities->errorResponse($validator->errors(), 11);
                }
                $sales = $this->activities->getBalances($request, new Sale, [['dispatcher_id', $this->user->id], ['schedule_id', $request->schedule_id]]);
                if (is_bool($sales)) {
                    return $this->activities->errorResponse('Las fechas son incorrectas.', 12);
                }
                if ($sales->count() == 0) {
                    return $this->activities->errorResponse('No cuenta con depositos en la cuenta', 13);
                }
                $data = array();
                foreach ($sales as $s) {
                    $sale['payment'] = $s->payment;
                    $sale['date'] = $s->created_at->format('Y/m/d');
                    $sale['hour'] = $s->created_at->format('H:i');
                    $sale['gasoline'] = $s->gasoline;
                    $sale['liters'] = $s->liters;
                    array_push($data, $sale);
                }
                return $this->activities->successReponse('sales', $data);
            case 5:
                $shopping = $this->activities->getBalances($request, new Sale, [['client_id', $this->user->id]]);
                if (is_bool($shopping)) {
                    return $this->activities->errorResponse('Las fechas son incorrectas.', 12);
                }
                if ($shopping->count() == 0) {
                    return $this->activities->errorResponse('No cuenta con depositos en la cuenta', 13);
                }
                $data = array();
                foreach ($shopping as $s) {
                    $sale['sale'] = $s->sale;
                    $sale['date'] = $s->created_at->format('Y/m/d H:i');
                    $sale['station'] = $s->station->name;
                    $sale['gasoline'] = $s->gasoline;
                    $sale['payment'] = $s->payment;
                    $sale['liters'] = $s->liters;
                    array_push($data, $sale);
                }
                return $this->activities->successReponse('shopping', $data);
            default:
                return $this->activities->logout(JWTAuth::getToken());
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        if ($this->user->role_id == 4) {
            $validator = Validator::make($request->all(), [
                'membership' => 'required|string',
                'gasoline' => 'required|string',
                'payment' => 'required|numeric',
                'liters' => 'required|numeric',
                'sale' => 'required|numeric'
            ]);
            if ($validator->fails()) {
                return $this->activities->errorResponse($validator->errors(), 11);
            }
            if (Sale::where([
                ['sale', $request->sale],
                ['company_id', $this->user->dispatcher->station->company_id],
                ['station_id', $this->user->dispatcher->station_id]
            ])->exists()) {
                return $this->activities->errorResponse('La venta ya ha sido registrada', 17);
            }
            $client = Client::where('membership', $request->membership)->first();
            if ($client == null) {
                return $this->activities->errorResponse('El cliente no existe', 404);
            }
            if ($request->sponsor == null) {
                $deposit = $client->user->deposits()->where([['status', 2], ['balance', '>=', $request->payment]])->first();
            } else {
                $sponsor = Client::where('membership', $request->sponsor)->first();
                if ($sponsor == null) {
                    return $this->activities->errorResponse('El beneficiario no existe', 404);
                }
                $deposit = SharedBalance::where([['sponsor_id', $sponsor->user_id], ['beneficiary_id', $client->user_id], ['status', 2], ['balance', '>=', $request->payment]])->first();
            }
            if ($deposit == null) {
                return $this->activities->errorResponse('Saldo insuficiente', 15);
            }
            $request->merge(['dispatcher_id' => $this->user->id]);
            $notification['message'] = 'Ejemplo de notificación';
            $notification['sale'] = $request->all();
            return $this->activities->successReponse('notification', $notification);
        }
        return $this->activities->logout(JWTAuth::getToken());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if ($this->user->role_id == 5) {
            $validator = Validator::make($request->all(), [
                'response' => 'required|string',
                'gasoline' => 'required|string',
                'payment' => 'required|numeric',
                'liters' => 'required|numeric',
                'sale' => 'required|numeric',
                'dispatcher_id' => 'required|integer',
                'no_bomb' => 'required|integer',
                'no_island' => 'required|integer'
            ]);
            if ($validator->fails()) {
                return $this->activities->errorResponse($validator->errors(), 11);
            }
            switch ($request->response) {
                case 'accept':
                    $dispatcher = User::find($request->dispatcher_id);
                    if ($dispatcher == null || $dispatcher->dispatcher == null) {
                        return $this->activities->errorResponse('El despachador no existe', 404);
                    }
                    if (Sale::where([
                        ['sale', $request->sale],
                        ['company_id', $dispatcher->dispatcher->station->company_id],
                        ['station_id', $dispatcher->dispatcher->station_id]
                    ])->exists()) {
                        return $this->activities->errorResponse('La venta ya ha sido registrada', 17);
                    }
                    if ($request->sponsor == null) {
                        $deposit = $this->user->deposits()->where([['status', 2], ['balance', '>=', $request->payment]])->first();
                    } else {
                        $sponsor = Client::where('membership', $request->sponsor)->first();
                        if ($sponsor == null) {
                            return $this->activities->errorResponse('El beneficiario no existe', 404);
                        }
                        $deposit = $this->user->beneficiary()->where([['sponsor_id', $sponsor->user_id], ['status', 2], ['balance', '>=', $request->payment]])->first();
                    }
                    if ($deposit == null) {
                        return $this->activities->errorResponse('Saldo insuficiente', 15);
                    }
                    $request->merge(isset($sponsor) ? ['sponsor_id' => $sponsor->user_id] : []);
                    $schedule = Schedule::where('station_id', $dispatcher->dispatcher->station_id)->whereTime('start', '<=', now()->format('H:i'))->whereTime('end', '>=', now()->format('H:i'))->first();
                    Sale::create(
                        $request->merge(
                            [
                                'company_id' => $dispatcher->dispatcher->station->company_id,
                                'station_id' => $dispatcher->dispatcher->station_id,
                                'client_id' => $this->user->id,
                                'dispatcher_id' => $dispatcher->id,
                                'time_id' => $dispatcher->times->last()->id,
                                'schedule_id' => $schedule->id
                            ]
                        )->all()
                    );
                    // Falta la suma de puntos para el cliente y el beneficiario
                    $deposit->balance -= $request->payment;
                    $deposit->save();
                    // envio de notificacion para el despachador
                    return $this->activities->successReponse('message', 'Cobro realizado correctamente');
                case 'deny':
                    // envio de notificacion para el despachador
                    return $this->activities->successReponse('notification', 'Canceled');
                default:
                    return $this->activities->errorResponse('Acción no reconocida', 18);
            }
        }
        return $this->activities->logout(JWTAuth::getToken());
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        if ($this->user->role_id == 4) {
            $time = $this->user->times->last();
            if ($time == null || $time->status == 6) {
                return $this->activities->errorResponse('Registre su turno para continuar', 16);
            }
            $data = array();
            if ($request->id == null) {
                foreach ($this->station->_bombs as $bomb) {
                    $b['id'] = $bomb->id;
                    $b['island-bomb'] = 'Isla ' . $bomb->island->number . ' - Bomba ' . $bomb->number;
                    array_push($data, $b);
                }
                return $this->activities->successReponse('islands-bombs', $data);
            }
            $bomb = Bomb::find($request->id);
            if ($bomb != null && $bomb->station_id == $this->station->id) {
                /* codigo temporal de una venta 
            aqui es donde se obtiene el data de las ventas para el caso de no tener https,
            si hay https o api lo hace la aplicación */
                $gasolines = ['Magna', 'Premium', 'Diesel'];
                $prices = [19.44, 20.33, 22.13];
                $index = rand(0, 2);
                $liters = rand(16, 2400) / 16;
                $payment = $liters * $prices[$index];
                $sale = rand(1000000, 9999999);

                $data['gasoline'] = $gasolines[$index];
                $data['payment'] = $payment;
                $data['liters'] = $liters;
                $data['sale'] = $sale;
                $data['no_bomb'] = 2;
                $data['no_island'] = 1;

                return $this->activities->successReponse('sale', $data);
            }
            return $this->activities->errorResponse('El número de bomba no existe o la estación es incorrecta', 404);
        }
        return $this->activities->logout(JWTAuth::getToken());
    }
    // Registro, inicio y fin de turno
    public function startEndTime(Request $request)
    {
        if ($this->user->role_id == 4) {
            $time = $this->user->times->last();
            switch ($request->time) {
                case 'start':
                    if ($time != null && $time->status == 4) {
                        return $this->activities->errorResponse('Finalice el turno actual para iniciar otro', 16);
                    }
                    $schedule = Schedule::where('station_id', $this->station->id)->whereTime('start', '<=', now()->format('H:i'))->whereTime('end', '>=', now()->format('H:i'))->first();
                    RegisterTime::create($request->merge(['user_id' => $this->user->id, 'station_id' => $this->station->id, 'schedule_id' => $schedule->id, 'status' => 4])->all());
                    return $this->activities->successReponse('message', 'Inicio de turno registrado');
                    // Ppsible codigo para pausar el turno
                case 'end':
                    if ($time != null && $time->status != 6) {
                        $time->update(['status' => 6]);
                        return $this->activities->successReponse('message', 'Fin de turno registrado');
                    }
                    return $this->activities->errorResponse('Turno no registrado o finalizado anteriormente', 16);
            }
            return $this->activities->errorResponse('Registro no válido', 16);
        }
        return $this->activities->logout(JWTAuth::getToken());
    }
    // Lista de los turnos de la estación
    public function getSchedules()
    {
        if ($this->user->role_id == 4) {
            $data = array();
            foreach ($this->station->schedules as $s) {
                $schedule['id'] = $s->id;
                $schedule['name'] = $s->name;
                array_push($data, $schedule);
            }
            return $this->activities->successReponse('schedules', $data);
        }
        return $this->activities->logout(JWTAuth::getToken());
    }
    // lista de las estaciones
    public function getStations()
    {
        if ($this->user->role_id == 5) {
            $stations = array();
            foreach (Station::all() as $station) {
                $data['id'] = $station->place_id;
                $data['name'] = $station->name;
                $data['address'] = $station->address;
                $data['phone'] = $station->phone;
                $data['email'] = $station->email;
                $data['latitude'] = $station->latitude;
                $data['longitude'] = $station->longitude;
                array_push($stations, $data);
            }
            return $this->activities->successReponse('stations', $stations);
        }
        return $this->activities->logout(JWTAuth::getToken());
    }
    // lista de precios de la estacion
    public function getPricesGasoline(Request $request)
    {
        if ($this->user->role_id == 5) {
            $validator = Validator::make($request->only('id'), ['id' => 'required|numeric']);
            if ($validator->fails()) {
                return $this->activities->errorResponse($validator->errors(), 11);
            }
            try {
                $apiPrices = new SimpleXMLElement('https://publicacionexterna.azurewebsites.net/publicaciones/prices', NULL, TRUE);
                $prices = array();
                foreach ($apiPrices->place as $place) {
                    if ($place['place_id'] == $request->id) {
                        foreach ($place->gas_price as $price) {
                            $prices["{$price['type']}"] = (float) $price;
                        }
                        return $this->activities->successReponse('prices', $prices);
                    }
                }
                return $prices;
            } catch (Exception $e) {
                return $this->activities->errorResponse('Intente más tarde', 19);
            }
            return 'precios de la gasolina';
        }
        return $this->activities->logout(JWTAuth::getToken());
    }
}