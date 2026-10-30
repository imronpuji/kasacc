<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\ReportExporter;
use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, ReportExporter $exporter)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest(url('/admin/login'));
        }
        abort_unless($user->can('view-reports'), 403);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true) && isset(ReportExporter::REPORTS[$report]), 404);

        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $from = $valid($request->query('from')) ? $request->query('from') : now()->startOfMonth()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : now()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $branchId = (int) $request->query('branch') ?: null;
        // Non-pemilik hanya boleh mengunduh cabangnya sendiri.
        if (! $user->hasRole('owner')) {
            $allowed = $user->branches()->pluck('branches.id');
            abort_if($branchId && ! $allowed->contains($branchId), 403);
            $branchId ??= $allowed->first();
        }

        $data = $exporter->build($report, $branchId, $from, $to);
        $data['branch'] = $branchId ? Branch::find($branchId)?->name : 'Semua cabang';
        $name = 'laporan-'.$report.'-'.$from.'-'.$to.'.'.$format;

        if ($format === 'pdf') {
            return Pdf::loadView('report-export', $data)->setPaper('a4', count($data['rows'][0]) > 3 ? 'landscape' : 'portrait')->download($name);
        }

        return Excel::download(new \App\Exports\ReportSheet($data), $name);
    }
}
