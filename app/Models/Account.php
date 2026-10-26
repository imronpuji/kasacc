<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        // K5: akun yang sudah punya transaksi tidak boleh dihapus, hanya dinonaktifkan.
        static::deleting(function (Account $account) {
            if (JournalLine::where('account_id', $account->id)->exists()) {
                throw new DomainException('Akun yang sudah punya transaksi tidak boleh dihapus; nonaktifkan saja.');
            }
        });
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'cogs', 'expense', 'other_expense'], true);
    }
}
