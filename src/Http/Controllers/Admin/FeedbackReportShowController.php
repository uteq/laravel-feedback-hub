<?php

namespace Uteq\FeedbackHub\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Uteq\FeedbackHub\Models\FeedbackReport;

class FeedbackReportShowController extends Controller
{
    public function __invoke(FeedbackReport $report): View
    {
        return view('feedback-hub::admin.show', [
            'report' => $report,
        ]);
    }
}
