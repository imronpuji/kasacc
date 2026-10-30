<?php

it('alamat lama (bahasa Indonesia) dialihkan ke alamat baru', function () {
    $this->get('/lacak/abc123')->assertRedirect('/track/abc123')->assertStatus(301);
    $this->get('/nota/SV-1')->assertRedirect('/service-receipt/SV-1');
    $this->get('/struk/POS-1')->assertRedirect('/receipt/POS-1');
    $this->get('/slip/5')->assertRedirect('/payslip/5');
    $this->get('/pelanggan/masuk')->assertRedirect('/customer/login');
    $this->get('/pelanggan/verifikasi')->assertRedirect('/customer/verify');
    $this->get('/pelanggan')->assertRedirect('/customer');
});
