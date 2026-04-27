<?php

namespace Modules\Essentials\Utils;

use App\Business;
use App\Transaction;
use App\Utils\Util;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Facades\View;
use Modules\Essentials\Entities\EssentialsAllowanceAndDeduction;
use Modules\Essentials\Entities\EssentialsAttendance;
use Modules\Essentials\Entities\EssentialsLeave;
use Modules\Essentials\Entities\EssentialsUserShift;
use Modules\Essentials\Entities\Shift;
use Modules\Essentials\Entities\EssentialsHoliday;


class EssentialsUtil extends Util
{
    /**
     * Function to calculate total work duration of a user for a period of time
     *
     * @param  string  $unit
     * @param  int  $user_id
     * @param  int  $business_id
     * @param  int  $start_date = null
     * @param  int  $end_date = null
     */
    public function getTotalWorkDuration(
        $unit,
        $user_id,
        $business_id,
        $start_date = null,
        $end_date = null
    ) {
        $total_work_duration = 0;
        if ($unit == 'hour') {
            $query = EssentialsAttendance::where('business_id', $business_id)
                                        ->where('user_id', $user_id)
                                        ->whereNotNull('clock_out_time');

            if (! empty($start_date) && ! empty($end_date)) {
                $query->whereDate('clock_in_time', '>=', $start_date)
                            ->whereDate('clock_in_time', '<=', $end_date);
            }

            $minutes_sum = $query->select(DB::raw('SUM(TIMESTAMPDIFF(MINUTE, clock_in_time, clock_out_time)) as total_minutes'))->first();
            $total_work_duration = ! empty($minutes_sum->total_minutes) ? $minutes_sum->total_minutes / 60 : 0;
        }

        return number_format($total_work_duration, 2);
    }

    /**
     * Parses month and year from date
     *
     * @param  string  $month_year
     */
    public function getDateFromMonthYear($month_year)
    {
        $month_year_arr = explode('/', $month_year);
        $month = $month_year_arr[0];
        $year = $month_year_arr[1];

        $transaction_date = $year.'-'.$month.'-01';

        return $transaction_date;
    }

    /**
     * Retrieves all allowances and deductions of an employeee
     *
     * @param  int  $business_id
     * @param  int  $user_id
     * @param  string  $start_date = null
     * @param  string  $end_date = null
     */
    public function getEmployeeAllowancesAndDeductions($business_id, $user_id, $start_date = null, $end_date = null)
    {
        $query = EssentialsAllowanceAndDeduction::join('essentials_user_allowance_and_deductions as euad', 'euad.allowance_deduction_id', '=', 'essentials_allowances_and_deductions.id')
                ->where('business_id', $business_id)
                ->where('euad.user_id', $user_id);

        //Filter if applicable one
        if (! empty($start_date) && ! empty($end_date)) {
            $query->where(function ($q) use ($start_date, $end_date) {
                $q->whereNull('applicable_date')
                    ->orWhereBetween('applicable_date', [$start_date, $end_date]);
            });
        }
        $allowances_and_deductions = $query->get();

        return $allowances_and_deductions;
    }

    /**
     * Validates user clock in and returns available shift id
     */
    public function checkUserShift($user_id, $settings, $clock_in_time = null)
    {
        $shift_id = null;
        $clock_in_datetime = ! empty($clock_in_time) ? \Carbon::parse($clock_in_time) : \Carbon::now();
        $clock_in_date = $clock_in_datetime->format('Y-m-d');
        $clock_in_time = $clock_in_datetime->format('H:i');
        
        $day_string = strtolower($clock_in_datetime->format('l'));
        $grace_before_checkin = ! empty($settings['grace_before_checkin']) ? (int) $settings['grace_before_checkin'] : 0;
        $grace_after_checkin = ! empty($settings['grace_after_checkin']) ? (int) $settings['grace_after_checkin'] : 0;
        
        //$clock_in_start = ! empty($clock_in_time) ? \Carbon::parse($clock_in_time)->subMinutes($grace_before_checkin) : \Carbon::now()->subMinutes($grace_before_checkin);
        //$clock_in_end = ! empty($clock_in_time) ? \Carbon::parse($clock_in_time)->addMinutes($grace_after_checkin) : \Carbon::now()->addMinutes($grace_after_checkin);

        $user_shifts = EssentialsUserShift::join('essentials_shifts as s', 's.id', '=', 'essentials_user_shifts.essentials_shift_id')
                    ->where('user_id', $user_id)
                    ->where('start_date', '<=', $clock_in_date)
                    ->where(function ($q) use ($clock_in_date) {
                        $q->whereNull('end_date')
                        ->orWhere('end_date', '>=', $clock_in_date);
                    })
                    ->select('essentials_user_shifts.*', 's.holidays', 's.start_time', 's.end_time', 's.type')
                    ->get();

                    
        foreach ($user_shifts as $shift) {
            $holidays = json_decode($shift->holidays, true);
            //check if holiday
            if (is_array($holidays) && in_array($day_string, $holidays)) {
                continue;
            }

            //Check allocated shift time
            if (! empty($shift->start_time)) {

                $start_start_time = \Carbon::parse($shift->start_time)->subMinutes($grace_before_checkin);
                $start_end_time = \Carbon::parse($shift->start_time)->addMinutes($grace_after_checkin);

                if(\Carbon::parse($clock_in_time)->between($start_start_time, $start_end_time)){
                    return $shift->essentials_shift_id;
                }
            }

            if ($shift->type == 'flexible_shift') {
                return $shift->essentials_shift_id;
            }
        }

        return $shift_id;
    }

    /**
     * Validates user clock out
     */
    public function canClockOut($clock_in, $settings, $clock_out_time = null)
    {
        $shift = Shift::find($clock_in->essentials_shift_id);
        if (empty($shift->end_time)) {
            return true;
        }
        $grace_before_checkout = ! empty($settings['grace_before_checkout']) ? (int) $settings['grace_before_checkout'] : 0;
        $grace_after_checkout = ! empty($settings['grace_after_checkout']) ? (int) $settings['grace_after_checkout'] : 0;

        if ($shift->type != 'flexible_shift') {

            $end_start_time = \Carbon::parse($shift->end_time)->subMinutes($grace_before_checkout);
            $end_end_time = \Carbon::parse($shift->end_time)->addMinutes($grace_after_checkout);

            if(\Carbon::parse($clock_out_time)->between($end_start_time, $end_end_time)){
                return true;
            }
        } elseif ($shift->type == 'flexible_shift') {
            return true;
        } else {
            return false;
        }
    }

    public function clockin($data, $essentials_settings)
    {
        //Check user can clockin
        $clock_in_time = is_object($data['clock_in_time']) ? $data['clock_in_time']->toDateTimeString() : $data['clock_in_time'];

        $shift = $this->checkUserShift($data['user_id'], $essentials_settings, $clock_in_time);

        if (empty($shift)) {
            $available_shifts = $this->getAllAvailableShiftsForGivenUser($data['business_id'], $data['user_id']);

            $available_shifts_html = view('essentials::attendance.avail_shifts')
                                        ->with(compact('available_shifts'))
                                        ->render();

            $output = ['success' => false,
                'msg' => __('essentials::lang.shift_not_allocated'),
                'type' => 'clock_in',
                'shift_details' => $available_shifts_html,
            ];

            return $output;
        }

        $data['essentials_shift_id'] = $shift;

        //Check if already clocked in
        $count = EssentialsAttendance::where('business_id', $data['business_id'])
                                ->where('user_id', $data['user_id'])
                                ->whereNull('clock_out_time')
                                ->count();
        if ($count == 0) {
            EssentialsAttendance::create($data);

            $shift_info = Shift::getGivenShiftInfo($data['business_id'], $shift);
            $current_shift_html = view('essentials::attendance.current_shift')
                                    ->with(compact('shift_info'))
                                    ->render();

            $output = ['success' => true,
                'msg' => __('essentials::lang.clock_in_success'),
                'type' => 'clock_in',
                'current_shift' => $current_shift_html,
            ];
        } else {
            $output = ['success' => false,
                'msg' => __('essentials::lang.already_clocked_in'),
                'type' => 'clock_in',
            ];
        }

        return $output;
    }

    public function clockout($data, $essentials_settings)
    {

        //Get clock in
        $clock_in = EssentialsAttendance::where('business_id', $data['business_id'])
                                ->where('user_id', $data['user_id'])
                                ->whereNull('clock_out_time')
                                ->first();
        $clock_out_time = is_object($data['clock_out_time']) ? $data['clock_out_time']->toDateTimeString() : $data['clock_out_time'];

        if (! empty($clock_in)) {
            $can_clockout = $this->canClockOut($clock_in, $essentials_settings, $clock_out_time);
            if (! $can_clockout) {
                $output = ['success' => false,
                    'msg' => __('essentials::lang.shift_not_over'),
                    'type' => 'clock_out',
                ];

                return $output;
            }

            $clock_in->clock_out_time = $data['clock_out_time'];
            $clock_in->clock_out_note = $data['clock_out_note'];
            $clock_in->clock_out_location = $data['clock_out_location'] ?? '';
            $clock_in->save();

            $output = ['success' => true,
                'msg' => __('essentials::lang.clock_out_success'),
                'type' => 'clock_out',
            ];
        } else {
            $output = ['success' => false,
                'msg' => __('essentials::lang.not_clocked_in'),
                'type' => 'clock_out',
            ];
        }

        return $output;
    }

    public function getAllAvailableShiftsForGivenUser($business_id, $user_id)
    {
        $available_user_shifts = EssentialsUserShift::join('essentials_shifts as s', 's.id', '=',
                                    'essentials_user_shifts.essentials_shift_id')
                                    ->where('user_id', $user_id)
                                    ->where('s.business_id', $business_id)
                                    ->whereDate('start_date', '<=', \Carbon::today())
                                    ->whereDate('end_date', '>=', \Carbon::today())
                                    ->select('essentials_user_shifts.start_date', 'essentials_user_shifts.end_date',
                                        's.name', 's.type', 's.start_time', 's.end_time', 's.holidays')
                                    ->get();

        return $available_user_shifts;
    }

    /**
     * get total leaves of and employee for given date
     *
     * @param  int  $business_id
     * @param  int  $employee_id
     * @param  string  $start_date
     * @param  string  $end_date
     */
    public function getTotalLeavesForGivenDateOfAnEmployee($business_id, $employee_id, $start_date, $end_date)
    {
        $leaves = EssentialsLeave::where('business_id', $business_id)
                        ->where('user_id', $employee_id)
                        ->whereDate('start_date', '>=', $start_date)
                        ->whereDate('end_date', '<=', $end_date)
                        ->get();

        $total_leaves = 0;
        foreach ($leaves as $key => $leave) {
            $start_date = \Carbon::parse($leave->start_date);
            $end_date = \Carbon::parse($leave->end_date);

            $diff = $start_date->diffInDays($end_date);
            $diff += 1;
            $total_leaves += $diff;
        }

        return $total_leaves;
    }

    public function getTotalDaysWorkedForGivenDateOfAnEmployee($business_id, $employee_id, $start_date, $end_date)
    {
        $attendances = EssentialsAttendance::where('business_id', $business_id)
                        ->where('user_id', $employee_id)
                        ->whereNotNull('clock_out_time')
                        ->whereDate('clock_in_time', '>=', $start_date)
                        ->whereDate('clock_in_time', '<=', $end_date)
                        ->get()
                        ->groupBy(function ($attendance, $key) {
                            return \Carbon::parse($attendance->clock_in_time)->format('Y-m-d');
                        });

        return count($attendances);
    }

    public function getPayrollQuery($business_id)
    {
        $payrolls = Transaction::where('transactions.business_id', $business_id)
                    ->where('type', 'payroll')
                    ->join('users as u', 'u.id', '=', 'transactions.expense_for')
                    ->leftJoin('categories as dept', 'u.essentials_department_id', '=', 'dept.id')
                    ->leftJoin('categories as dsgn', 'u.essentials_designation_id', '=', 'dsgn.id')
                    ->leftJoin('essentials_payroll_group_transactions as epgt', 'transactions.id', '=', 'epgt.transaction_id')
                    ->leftJoin('essentials_payroll_groups as epg', 'epgt.payroll_group_id', '=', 'epg.id')
                    ->select([
                        'transactions.id',
                        DB::raw("CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as user"),
                        'final_total',
                        'transaction_date',
                        'ref_no',
                        'transactions.payment_status',
                        'dept.name as department',
                        'dsgn.name as designation',
                        'epgt.payroll_group_id',
                    ]);

        return $payrolls;
    }

    public function getEssentialsSettings()
    {
        $settings = request()->session()->get('business.essentials_settings');
        $settings = ! empty($settings) ? json_decode($settings, true) : [];

        return $settings;
    }

    public function Gettotalholiday($business_id, $location, $start_date, $end_date, $permitted_locations){
        $holidays = EssentialsHoliday::where('essentials_holidays.business_id', $business_id)
                        ->leftJoin('business_locations as bl', 'bl.id', '=', 'essentials_holidays.location_id')
                        ->select([
                            'essentials_holidays.id',
                            'essentials_holidays.name',
                            'bl.name as location',
                            'start_date',
                            'end_date',
                            'note',
                        ]);

            if ($permitted_locations != 'all') {
                $holidays->where(function ($query) use ($permitted_locations) {
                    $query->whereIn('essentials_holidays.location_id', $permitted_locations)
                        ->orWhereNull('essentials_holidays.location_id');
                });
            }

            if (! empty($location)) {
                $holidays->where('essentials_holidays.location_id', $location);
            }

            if (! empty($start_date) && ! empty($end_date)) {
                $holidays->whereDate('essentials_holidays.start_date', '>=', $start_date)
                            ->whereDate('essentials_holidays.start_date', '<=', $end_date);
            }

            return $holidays;
    }

    /**
     * Monthly attendance calendar + summary for Connector API (getAttendanceByDate).
     *
     * Day status codes: 1 attended (on time), 2 late, 4 absent/workday without punch,
     * 5 vacation (approved leave), 6 weekend (per shift), 7 public holiday.
     *
     * @param  int  $business_id
     * @param  int  $user_id
     * @param  int  $year
     * @param  int  $month
     * @param  \App\User|null  $authUser  Used for holiday location filtering (optional).
     */
    public function getAttendanceByDateForApi($business_id, $user_id, $year, $month, $authUser = null)
    {
        $monthStart = Carbon::createFromDate((int) $year, (int) $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $paddingStart = $monthStart->copy()->subDays(7);
        $paddingEnd = $monthEnd->copy()->addDays(7);

        $settings = [];
        $business = Business::find($business_id);
        if (! empty($business->essentials_settings)) {
            $settings = json_decode($business->essentials_settings, true) ?: [];
        }

        $graceAfterCheckin = ! empty($settings['grace_after_checkin']) ? (int) $settings['grace_after_checkin'] : 0;

        $attendanceRows = EssentialsAttendance::where('business_id', $business_id)
            ->where('user_id', $user_id)
            ->whereDate('clock_in_time', '>=', $paddingStart->format('Y-m-d'))
            ->whereDate('clock_in_time', '<=', $paddingEnd->format('Y-m-d'))
            ->orderBy('clock_in_time')
            ->get()
            ->groupBy(function ($row) {
                return Carbon::parse($row->clock_in_time)->format('Y-m-d');
            });

        $summary = [
            'attended' => 0,
            'late' => 0,
            'absent' => 0,
            'out' => 0,
            'vacation' => 0,
            'weekend' => 0,
            'no_clockout' => 0,
            'total_late_minutes' => 0,
            'total_overtime_minutes' => 0,
            'month_name' => $monthStart->format('F'),
        ];

        $days = [];
        $cursor = $monthStart->copy();
        while ($cursor->lte($monthEnd)) {
            $dayRow = $this->buildAttendanceApiDay(
                $cursor,
                $business_id,
                $user_id,
                $attendanceRows,
                $graceAfterCheckin,
                $authUser,
                true
            );
            $days[] = $dayRow['payload'];
            foreach ($dayRow['counters'] as $key => $delta) {
                if ($key === 'total_late_minutes' || $key === 'total_overtime_minutes') {
                    $summary[$key] += $delta;
                } elseif (array_key_exists($key, $summary)) {
                    $summary[$key] += $delta;
                }
            }
            $cursor->addDay();
        }

        $days_before = [];
        $b = $monthStart->copy()->subDays(7);
        for ($i = 0; $i < 7; $i++) {
            $row = $this->buildAttendanceApiDay(
                $b,
                $business_id,
                $user_id,
                $attendanceRows,
                $graceAfterCheckin,
                $authUser,
                false
            );
            $days_before[] = $row['payload'];
            $b->addDay();
        }

        $days_after = [];
        $a = $monthEnd->copy()->addDay();
        for ($i = 0; $i < 7; $i++) {
            $row = $this->buildAttendanceApiDay(
                $a,
                $business_id,
                $user_id,
                $attendanceRows,
                $graceAfterCheckin,
                $authUser,
                false
            );
            $days_after[] = $row['payload'];
            $a->addDay();
        }

        return array_merge($summary, [
            'days_before' => $days_before,
            'days' => $days,
            'days_after' => $days_after,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection|null  $attendanceRows  keyed by Y-m-d
     */
    protected function buildAttendanceApiDay(
        Carbon $date,
        $business_id,
        $user_id,
        $attendanceRows,
        int $graceAfterCheckin,
        $authUser,
        bool $countTowardSummary
    ) {
        $dateStr = $date->format('Y-m-d');
        $number_in_week = (int) $date->format('w') + 1;

        $shift = $this->getShiftForUserOnDate($business_id, $user_id, $date);
        $isWeekend = $this->isOffDayFromShift($date, $shift);
        $isPublicHoliday = $this->isPublicHolidayOnDate($business_id, $dateStr, $authUser);
        $isVacation = $this->isApprovedLeaveOnDate($business_id, $user_id, $dateStr);

        /** @var \Illuminate\Support\Collection|null $dayAttendances */
        $dayAttendances = $attendanceRows->get($dateStr);
        $attendance = $dayAttendances && $dayAttendances->count() ? $dayAttendances->first() : null;

        $status = 4;
        $lateMinutes = 0;
        $overtimeMinutes = 0;
        $startTime = null;
        $endTime = null;
        $clockInNote = '';

        $counters = [
            'attended' => 0,
            'late' => 0,
            'absent' => 0,
            'out' => 0,
            'vacation' => 0,
            'weekend' => 0,
            'no_clockout' => 0,
            'total_late_minutes' => 0,
            'total_overtime_minutes' => 0,
        ];

        if ($isPublicHoliday) {
            $status = 7;
        } elseif ($isVacation) {
            $status = 5;
            if ($countTowardSummary) {
                $counters['vacation'] = 1;
            }
        } elseif ($isWeekend) {
            $status = 6;
            if ($countTowardSummary) {
                $counters['weekend'] = 1;
            }
        } elseif (! empty($attendance)) {
            $clockInNote = (string) ($attendance->clock_in_note ?? '');
            $startTime = $attendance->clock_in_time ? Carbon::parse($attendance->clock_in_time)->format('Y-m-d H:i:s') : null;
            $endTime = $attendance->clock_out_time ? Carbon::parse($attendance->clock_out_time)->format('Y-m-d H:i:s') : null;

            if (empty($attendance->clock_out_time) && $countTowardSummary) {
                $counters['no_clockout'] = 1;
            }

            if ($shift && $shift->type === 'fixed_shift' && ! empty($shift->start_time)) {
                $shiftStart = Carbon::parse($dateStr.' '.Carbon::parse($shift->start_time)->format('H:i:s'));
                $clockInCarbon = Carbon::parse($attendance->clock_in_time);
                $allowedUntil = $shiftStart->copy()->addMinutes($graceAfterCheckin);
                if ($clockInCarbon->gt($allowedUntil)) {
                    $lateMinutes = (int) $allowedUntil->diffInMinutes($clockInCarbon);
                    $status = 2;
                    if ($countTowardSummary) {
                        $counters['late'] = 1;
                        $counters['total_late_minutes'] = $lateMinutes;
                    }
                } else {
                    $status = 1;
                    if ($countTowardSummary) {
                        $counters['attended'] = 1;
                    }
                }
            } else {
                $status = 1;
                if ($countTowardSummary) {
                    $counters['attended'] = 1;
                }
            }

            if ($countTowardSummary && ! empty($attendance->clock_out_time) && $shift && $shift->type === 'fixed_shift' && ! empty($shift->end_time)) {
                $shiftEnd = Carbon::parse($dateStr.' '.Carbon::parse($shift->end_time)->format('H:i:s'));
                $clockOutCarbon = Carbon::parse($attendance->clock_out_time);
                if ($clockOutCarbon->gt($shiftEnd)) {
                    $overtimeMinutes = (int) $shiftEnd->diffInMinutes($clockOutCarbon);
                    $counters['total_overtime_minutes'] = $overtimeMinutes;
                }
            }
        } else {
            $shouldCountAbsent = $countTowardSummary && ! $isWeekend && ! $isPublicHoliday && ! $isVacation && ! $date->isFuture();
            if ($shouldCountAbsent) {
                $status = 4;
                $counters['absent'] = 1;
            }
        }

        $payload = [
            'number_in_month' => $date->day,
            'number_in_week' => $number_in_week,
            'month' => (int) $date->format('n'),
            'year' => (int) $date->format('Y'),
            'name' => $date->format('l'),
            'status' => $status,
            'clock_in_note' => $clockInNote,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => $overtimeMinutes,
        ];

        return ['payload' => $payload, 'counters' => $counters];
    }

    /**
     * @return Shift|null
     */
    protected function getShiftForUserOnDate($business_id, $user_id, Carbon $date)
    {
        $dateStr = $date->format('Y-m-d');
        $row = EssentialsUserShift::join('essentials_shifts as s', 's.id', '=', 'essentials_user_shifts.essentials_shift_id')
            ->where('essentials_user_shifts.user_id', $user_id)
            ->where('s.business_id', $business_id)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('essentials_user_shifts.start_date')
                    ->orWhereDate('essentials_user_shifts.start_date', '<=', $dateStr);
            })
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('essentials_user_shifts.end_date')
                    ->orWhereDate('essentials_user_shifts.end_date', '>=', $dateStr);
            })
            ->orderBy('essentials_user_shifts.id')
            ->select('s.*')
            ->first();

        return $row ? Shift::find($row->id) : null;
    }

    protected function isOffDayFromShift(Carbon $date, $shift): bool
    {
        $dayString = strtolower($date->format('l'));
        $weekendDays = ['friday', 'saturday'];
        if ($shift && ! empty($shift->holidays)) {
            $h = $shift->holidays;
            $h = is_array($h) ? $h : json_decode((string) $h, true);
            if (is_array($h) && count($h)) {
                $weekendDays = array_map('strtolower', $h);
            }
        }

        return in_array($dayString, $weekendDays, true);
    }

    protected function isPublicHolidayOnDate($business_id, $dateStr, $authUser): bool
    {
        $q = EssentialsHoliday::where('business_id', $business_id)
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr);

        if ($authUser) {
            $permitted = $authUser->permitted_locations($business_id);
            if ($permitted != 'all') {
                $q->where(function ($query) use ($permitted) {
                    $query->whereIn('location_id', $permitted)
                        ->orWhereNull('location_id');
                });
            }
        }

        return $q->exists();
    }

    protected function isApprovedLeaveOnDate($business_id, $user_id, $dateStr): bool
    {
        return EssentialsLeave::where('business_id', $business_id)
            ->where('user_id', $user_id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->exists();
    }
}
