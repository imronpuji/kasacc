<?php

namespace App\Http\Controllers;

use App\Domain\Customer\OtpService;
use App\Domain\Service\ServiceTicketService;
use App\Models\ServiceTicket;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Halaman untuk pelanggan toko: tautan lacak (tanpa masuk) dan portal (masuk dengan nomor HP + kode sekali pakai). */
class CustomerPortalController extends Controller
{
    /** Status satu servis lewat tautan di nota. Hanya status dan riwayat proses; tanpa nominal, nomor HP, atau data lain. */
    public function track(string $token)
    {
        $ticket = ServiceTicket::with('branch')->where('public_token', $token)->firstOrFail();
        $logs = DB::table('service_progress_logs')->where('service_ticket_id', $ticket->id)->orderBy('id')->get(['progress', 'note', 'created_at']);

        return view('pelanggan.lacak', ['t' => $ticket, 'status' => ServiceTicketService::publicStatus($ticket), 'logs' => $logs]);
    }

    public function loginForm()
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('pelanggan.beranda');
        }

        return view('pelanggan.masuk');
    }

    public function sendCode(Request $request, OtpService $otp)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']], ['phone.required' => 'Isi nomor HP Anda.']);
        try {
            $otp->request($data['phone']);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        // Pesan sama untuk nomor terdaftar maupun tidak, supaya nomor orang lain tidak bisa ditebak.
        return redirect()->route('pelanggan.verifikasi')->with('phone', $data['phone']);
    }

    public function verifyForm(Request $request)
    {
        $phone = $request->session()->get('phone', $request->old('phone'));
        if (! $phone) {
            return redirect()->route('pelanggan.masuk');
        }

        return view('pelanggan.kode', ['phone' => $phone]);
    }

    public function verify(Request $request, OtpService $otp)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30'], 'code' => ['required', 'string', 'max:10']], ['code.required' => 'Isi kode yang dikirim ke HP Anda.']);
        try {
            $customer = $otp->verify($data['phone'], $data['code']);
        } catch (DomainException $e) {
            return back()->withInput(['phone' => $data['phone']])->withErrors(['code' => $e->getMessage()]);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->route('pelanggan.beranda');
    }

    public function home()
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return redirect()->route('pelanggan.masuk');
        }

        return view('pelanggan.beranda', [
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

        return redirect()->route('pelanggan.masuk');
    }
}
