<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\DeleteAccountCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class DeleteAccountController extends Controller
{
    public function show()
    {
        return view('auth.delete-account');
    }

    public function sendCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);

        // Не раскрываем, существует ли email (защита от перебора)
        $user = User::where('email', $email)->first();

        if ($user && !str_starts_with($user->email, 'deleted_')) {
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            Cache::put("delete_account:{$email}", $code, now()->addMinutes(10));
            Mail::to($email)->send(new DeleteAccountCode($code));
        }

        return back()
            ->with('code_sent', true)
            ->with('email', $email);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|digits:6',
        ]);

        $email = strtolower($request->email);
        $cacheKey = "delete_account:{$email}";
        $cached = Cache::get($cacheKey);

        if (!$cached || $cached !== $request->code) {
            return back()
                ->withInput()
                ->withErrors(['code' => 'Неверный или устаревший код.']);
        }

        Cache::forget($cacheKey);

        $user = User::where('email', $email)->first();

        if ($user && !str_starts_with($user->email, 'deleted_')) {
            $user->forceFill([
                'name'           => 'Удалённый пользователь',
                'first_name'     => 'Удалённый',
                'last_name'      => 'пользователь',
                'email'          => 'deleted_' . $user->id . '@padel.local',
                'phone'          => null,
                'password'       => null,
                'avatar'         => null,
                'telegram_id'    => null,
                'remember_token' => null,
            ])->save();

            $user->tokens()->delete();
        }

        return redirect()->route('delete-account.done');
    }

    public function done()
    {
        return view('auth.delete-account-done');
    }
}
