<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>@yield('title', 'Pelanggan')</title>
<style>
body{font-family:system-ui,sans-serif;background:#f3f4f6;color:#111;margin:0;padding:16px}
.box{background:#fff;max-width:480px;margin:0 auto 16px;padding:20px;border-radius:12px;border:1px solid #e5e7eb}
h1{font-size:20px;margin:0 0 4px}h2{font-size:14px;margin:0 0 8px;color:#4b5563;text-transform:uppercase}
p{font-size:14px;color:#4b5563;margin:4px 0 12px}
label{display:block;font-size:14px;margin-bottom:6px}
input[type=text],input[type=tel]{width:100%;box-sizing:border-box;padding:12px;font-size:18px;border:1px solid #d1d5db;border-radius:8px}
button{width:100%;margin-top:12px;padding:12px;font-size:16px;border:0;border-radius:8px;background:#0B63F6;color:#fff;cursor:pointer}
button.link{background:none;color:#0B63F6;width:auto;padding:0;margin:0;font-size:14px;text-decoration:underline}
.err{background:#FDECEA;color:#B42318;padding:8px 10px;border-radius:6px;font-size:14px;margin-bottom:10px}
.row{display:flex;justify-content:space-between;gap:8px;font-size:14px;padding:8px 0;border-top:1px solid #f1f2f4}
.row:first-of-type{border-top:0}.muted{color:#6b7280;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;background:#E7F0FE;color:#0B63F6;font-size:12px;font-weight:600}
</style></head><body>@yield('content')</body></html>
