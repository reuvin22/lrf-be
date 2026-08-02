<?php

use App\Http\Controllers\v1\AttendanceController;
use App\Http\Controllers\v1\AttendanceSubSegmentController;
use App\Http\Controllers\v1\CompanyCalendarController;
use App\Http\Controllers\v1\ConstructionSitesController;
use App\Http\Controllers\v1\DayTypeController;
use App\Http\Controllers\v1\EmployeeController;
use App\Http\Controllers\v1\InvoiceDocumentController;
use App\Http\Controllers\v1\InvoiceSummaryController;
use App\Http\Controllers\v1\OcrCategoriesController;
use App\Http\Controllers\v1\OcrUploadController;
use App\Http\Controllers\v1\RatesController;
use App\Http\Controllers\v1\SalaryController;
use App\Http\Controllers\v1\SegmentController;
use App\Http\Controllers\v1\SiteAssignmentController;
use App\Http\Controllers\v1\SiteExpenseCategoryController;
use App\Http\Controllers\v1\SiteSubContractorController;
use App\Http\Controllers\v1\SubContractorController;
use App\Http\Controllers\v1\SubContractorReportController;
use App\Http\Controllers\v1\SubContractorWorkersController;
use App\Http\Controllers\v1\SystemSettingController;
use App\Http\Controllers\v1\TransportationExpenseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::delete('transportation_expenses/bulk-delete', [TransportationExpenseController::class, 'bulkDelete']);
    Route::resource('attendances', AttendanceController::class);
    Route::resource('segments', SegmentController::class);
    Route::resource('transportation_expenses', TransportationExpenseController::class);
    Route::resource('employees', EmployeeController::class);
    Route::resource('subcontractors', SubContractorController::class);
    Route::resource('sites', ConstructionSitesController::class);
    Route::resource('site-assignment', SiteAssignmentController::class);
    Route::resource('subcontractor-workers', SubContractorController::class);
    Route::resource('site-subcontractors', SiteSubContractorController::class);
    Route::resource('subcontractor-reports', SubContractorReportController::class);
    Route::resource('subcontractor-workers', SubContractorWorkersController::class);
    Route::resource('attendance-subcontractor-segments', AttendanceSubSegmentController::class);
    Route::patch('ocr-uploads/{id}/review', [OcrUploadController::class, 'review']);
    Route::resource('ocr-uploads', OcrUploadController::class);
    Route::patch('invoice-documents/{id}/confirm', [InvoiceDocumentController::class, 'confirm']);
    Route::resource('invoice-documents', InvoiceDocumentController::class);
    Route::get('invoice-summary/sites/{site_id}', [InvoiceSummaryController::class, 'sites']);
    Route::get('invoice-summary', [InvoiceSummaryController::class, 'index']);
    Route::resource('rates', RatesController::class);
    Route::resource('company-calendar', CompanyCalendarController::class);
    Route::resource('day-types', DayTypeController::class);
    Route::resource('settings', SystemSettingController::class);
    Route::resource('ocr-categories', OcrCategoriesController::class);
    Route::resource('site-expense-categories', SiteExpenseCategoryController::class);
    Route::get('dashboard', [AttendanceController::class, 'dashboard']);
    Route::post('attendance-employee', [AttendanceController::class, 'attendance_employee']);
    Route::get('get-attendance-employee', [AttendanceController::class, 'get_attendance_employee_by_attendance']);
    Route::post('employee-site', [SiteAssignmentController::class, 'assignedSitesEmployee']);
    Route::get('get-attendance-subcontractor', [AttendanceSubSegmentController::class, 'getAttendanceSubcontractor']);
    Route::post('salary/calculate', [SalaryController::class, 'calculate']);
    Route::post('salary/close', [SalaryController::class, 'close']);
});
