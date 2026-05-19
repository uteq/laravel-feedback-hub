<?php

namespace Uteq\FeedbackHub\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Uteq\FeedbackHub\Models\FeedbackReport;

class FeedbackReportIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $reports = FeedbackReport::query()
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('feedback-hub::admin.index', [
            'reports' => $reports,
        ]);
    }
}
