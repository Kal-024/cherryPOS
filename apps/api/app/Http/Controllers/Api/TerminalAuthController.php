<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\TerminalAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Autenticación de la terminal: la primera mitad de la doble credencial (D-05). */
class TerminalAuthController extends Controller
{
    public function __construct(private TerminalAuthService $terminals) {}

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_code' => 'required|string|max:10',
            'terminal_code' => 'required|string|max:20',
            'secret' => 'required|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('validation.errors'),
                'errors' => $validator->errors(),
                'status' => 422,
            ], 422);
        }

        $data = $validator->validated();

        return response()->json([
            'message' => __('auth.terminal_authenticated'),
            'data' => $this->terminals->authenticate(
                $data['branch_code'],
                $data['terminal_code'],
                $data['secret']
            ),
            'status' => 200,
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => __('auth.terminal_logged_out'),
            'status' => 200,
        ], 200);
    }
}
