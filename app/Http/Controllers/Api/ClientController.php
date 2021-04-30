<?php

namespace App\Http\Controllers\Api;

use App\Client;
use App\Http\Controllers\Controller;
use App\Repositories\Activities;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;

class ClientController extends Controller
{
    private $activities;
    public function __construct(Activities $activities)
    {
        $this->activities = $activities;
    }
    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validation = $this->activities->validateDataUser($request);
        if (!(is_bool($validation))) {
            return $validation;
        }
        while (true) {
            $membership = 'C' . substr(Carbon::now()->format('Y'), 2) . rand(1000000, 9999999);
            if (!(Client::where('membership', $membership)->exists())) {
                $request->merge(['membership' => $membership]);
                break;
            }
        }
        $password = $request->password;
        $user = User::create($request->merge(['password' => bcrypt($request->password), 'role_id' => 5])->all());
        Client::create($request->merge(['user_id' => $user->id, 'points' => 0])->all());
        $request->merge(['password' => $password]);
        return $this->activities->getToken($request, $user);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        if (($user = Auth::user())->role_id == 5) {
            $data['name'] = $user->name . ' ' . $user->first_surname . ' ' . $user->second_surname;
            $data['membership'] = $user->client->membership;
            $data['current_balance'] = $user->deposits->where('status', 2)->sum('balance');
            $data['beneficiary'] = $user->beneficiary->where('status', 2)->sum('balance');
            return $this->activities->successReponse('user', $data);
        }
        return $this->activities->logout(JWTAuth::getToken());
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit()
    {
        if (($user = Auth::user())->role_id == 5) {
            $data['name'] = $user->name;
            $data['first_surname'] = $user->first_surname;
            $data['second_surname'] = $user->second_surname;
            $data['email'] = $user->email;
            $data['birthdate'] = $user->client->birthdate;
            $data['sex'] = $user->client->sex;
            $data['phone'] = $user->phone;
            $data['car'] = $user->client->car;
            return $this->activities->successReponse('user', $data);
        }
        return $this->activities->logout(JWTAuth::getToken());
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        if (($user = Auth::user())->role_id == 5) {
            $validation = $this->activities->validateDataUser($request, $user);
            if (!(is_bool($validation))) {
                return $validation;
            }
            if ($request->password != '') {
                $request->merge(['password' => bcrypt($request->password)]);
                $user->update($request->only(['password']));
            }
            $user->update($request->only(['name', 'first_surname', 'second_surname', 'email', 'phone']));
            $user->client->update($request->except(['user_id', 'membership', 'points', 'ids']));
            if ($request->password != '') {
                $this->activities->logout(JWTAuth::getToken(), true);
                return $this->activities->successReponse('message', 'Perfil y contraseña actualizados correctamente, Inicie sesion nuevamente');
            }
            return $this->activities->successReponse('message', 'Perfil actualizado correctamente');
        }
        return $this->activities->logout(JWTAuth::getToken());
    }
}
