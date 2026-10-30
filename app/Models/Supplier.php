<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pemasok barang. Saldo utangnya dihitung dari jurnal yang menunjuk pemasok ini, bukan disimpan di sini. */
class Supplier extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}
