<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function showRegistrationForm()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
       $request->validate([
           'name' => 'required|string|max:255',
           'email' => 'required|string|email|max:255|unique:users',
           'password' => ['required', 'confirmed', Password::defaults()],
       ]);
       User::create([
           'name' => $request->name,
           'email' => $request->email,
           // Cast User::password = hashed сам хеширует; Hash::make здесь дал бы двойной хеш и вход после активации не работал бы.
           'password' => $request->password,
           // Явно false: колонка default false, в кабинет не пускаем до активации.
           'active' => false,
       ]);

       return redirect()
           ->route('login')
           ->with('status', 'Регистрация принята. Вход откроется после активации учётной записи.');
    }
}
