<?php

namespace App\Http\Controllers;

use App\Domain\Customer\OtpService;
use App\Domain\Service\ServiceTicketService;
use App\Models\ServiceTicket;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Halaman untuk pelanggan toko: tautan lacak (tanpa masuk) dan portal (masuk dengan email + kode sekali pakai). */
class CustomerPortalController extends Controller
{
    /** Status satu servis lewat tautan di nota. Hanya status dan riwayat proses; tanpa nominal, nomor HP, atau data lain. */
    public function track(string $token)
    {
        $ticket = ServiceTicket::with('branch')->where('public_token', $token)->firstOrFail();
        $logs = DB::table('service_progress_logs')->where('service_ticket_id', $ticket->id)->orderBy('id')->get(['progress', 'note', 'created_at']);

        return view('customer.track', ['t' => $ticket, 'status' => ServiceTicketService::publicStatus($ticket), 'logs' => $logs]);
    }

    public function loginForm()
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('customer.home');
        }

        return view('customer.login');
    }

    public function sendCode(Request $request, OtpService $otp)
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:120']], ['email.required' => 'Isi email Anda.']);
        try {
            $otp->request($data['email']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()]);
        }

        // Pesan sama untuk nomor terdaftar maupun tidak, supaya nomor orang lain tidak bisa ditebak.
        return redirect()->route('customer.verify')->with('email', $data['email']);
    }

    public function verifyForm(Request $request)
    {
        $email = $request->session()->get('email', $request->old('email'));
        if (! $email) {
            return redirect()->route('customer.login');
        }

        return view('customer.send-code', ['email' => $email]);
    }

    public function verify(Request $request, OtpService $otp)
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:120'], 'code' => ['required', 'string', 'max:10']], ['code.required' => 'Isi kode yang dikirim ke HP Anda.']);
        try {
            $customer = $otp->verify($data['email'], $data['code']);
        } catch (DomainException $e) {
            return back()->withInput(['email' => $data['email']])->withErrors(['code' => $e->getMessage()]);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->route('customer.home');
    }

    public function home()
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return redirect()->route('customer.login');
        }

        return view('customer.home', [
            'customer' => $customer,
            'tickets' => $customer->tickets()->with('branch')->latest('id')->limit(50)->get(),
            'sales' => $customer->sales()->with('branch')->latest('id')->limit(50)->get(),
            'complaints' => $customer->complaints()->latest('id')->limit(20)->get(),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('customer.login');
    }
}
