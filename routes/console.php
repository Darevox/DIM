<?php

    use Illuminate\Foundation\Inspiring;
    use Illuminate\Support\Facades\Artisan;
    use App\Models\Subscription;

    Artisan::command('inspire', function () {
        $this->comment(Inspiring::quote());
    })->purpose('Display an inspiring quote')->hourly();
