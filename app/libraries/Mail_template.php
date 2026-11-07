<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Mail_template {
    private const EVENTS = [
        'submitted'=>['Booking submitted','Your request is awaiting review. Pending requests do not reserve resources.'],
        'approved'=>['Booking approved','An approval was recorded for your booking. Review its current status before using the resource.'],
        'rejected'=>['Booking rejected','Your request was rejected. Open the request to view the decision and reason.'],
        'cancelled'=>['Booking cancelled','A cancellation was recorded for your booking.'],
        'completed'=>['Booking completed','Completion was recorded for your booking.'],
        'release'=>['Asset release recorded','An asset handover was recorded. Open your request to review the released quantity and items.'],
        'return'=>['Asset return recorded','An asset return was recorded. Open your request for quantities and any remaining custody.'],
        'inspection'=>['Asset inspection recorded','An asset inspection was recorded. Review the request for the current item condition.'],
        'maintenance'=>['Booking availability update','Maintenance may affect your requested interval. Availability will be rechecked during review.'],
        'inventory_changed'=>['Asset availability update','Changed inventory may affect this booking. Contact the asset custodian.'],
        'facility_upcoming'=>['Upcoming facility reservation','Your approved facility booking is approaching.'],
        'asset_upcoming'=>['Upcoming asset booking','Your approved asset booking is approaching.'],
        'asset_return_due'=>['Asset return reminder','Your borrowed assets are due for return soon.'],
        'asset_overdue'=>['Overdue asset return','This asset booking has an overdue return. Review current custody and arrange the return.'],
        'facility_maintenance_overdue'=>['Facility maintenance overdue','The maintenance end time has passed and the record is still open.'],
        'asset_maintenance_overdue'=>['Asset maintenance overdue','The maintenance end time has passed and the record is still open.'],
        'account.created'=>['Campus account created','Your campus account has been created. Use the credentials provided by campus administration; no password is included here.'],
        'account.activated'=>['Campus account activated','Your campus account has been activated.'],
        'account.deactivated'=>['Campus account deactivated','Your campus account has been deactivated. Contact campus administration if you need help.'],
        'account.password_changed'=>['Campus password changed','Your campus password was changed and previous sessions were invalidated. If you did not do this, contact campus administration immediately.'],
        'maintenance.scheduled'=>['Maintenance scheduled','A maintenance schedule was recorded. Review its current status in the system.'],
        'maintenance.completed'=>['Maintenance completed','Completion was recorded for this maintenance task.'],
        'maintenance.cancelled'=>['Maintenance cancelled','A cancellation was recorded for this maintenance task.'],
        'asset_problem.submitted'=>['Asset problem reported','An asset problem report is awaiting administrator review.'],
        'asset_problem.assigned'=>['Asset problem assigned','A custodian assignment was recorded. Open the report for current details.'],
        'asset_problem.started'=>['Asset maintenance started','Work has started on this asset problem report.'],
        'asset_problem.progress'=>['Asset maintenance update','Progress was recorded for this asset problem report.'],
        'asset_problem.resolved'=>['Asset problem resolved','The linked maintenance work has been completed. Review the report for the recorded resolution.'],
        'asset_problem.rejected'=>['Asset problem report rejected','An administrator rejected this report. Open it to review the reason.'],
        'asset_problem.work_cancelled'=>['Asset maintenance cancelled','Linked work was cancelled. The problem report remains assigned for further action.'],
        'password_reset'=>['Reset your campus password','Use the button below to choose a new password. This link can be used once and expires after 30 minutes. If you did not request it, ignore this email.'],
    ];
    public function event(string $type): ?array
    {
        if (preg_match('/^(facility|asset)_booking\.(.+)$/D',$type,$match)) $type=$match[2];
        if (preg_match('/^(facility|asset)_maintenance\.(.+)$/D',$type,$match)) $type='maintenance.'.$match[2];
        return self::EVENTS[$type] ?? null;
    }
    public function render(string $type, string $recipient, array $fields, string $url): array
    {
        $event=$this->event($type);
        if (!$event) throw new DomainException('Unsupported email event.');
        [$title,$explanation]=$event;
        $escape=fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $text="Hello {$recipient},\n\n{$title}\n{$explanation}\n\n";
        $rows='';
        foreach($fields as $label=>$value) {
            if ($value===null || $value==='') continue;
            $rows.='<tr><th align="left" style="padding:8px 12px 8px 0;color:#53665e;font-weight:normal;vertical-align:top">'.$escape($label).'</th><td style="padding:8px 0">'.$escape($value).'</td></tr>';
            $text.=$label.': '.$value."\n";
        }
        $action=$type==='password_reset'?'Reset password':'Open campus system';
        $text.="\n{$action}: {$url}\n\nTimes are Asia/Manila (UTC+8).\nCFABS · Campus Facility & Asset Booking System\n";
        $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;background:#f4f7f4;color:#182c29;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="100%" style="max-width:600px;background:white;border:1px solid #dce5df;border-radius:12px" cellspacing="0" cellpadding="0"><tr><td style="padding:28px"><p style="color:#145d50;font-weight:bold;letter-spacing:1px">CFABS</p><h1 style="font-size:24px;line-height:1.3">'.$escape($title).'</h1><p>Hello '.$escape($recipient).',</p><p style="line-height:1.6">'.$escape($explanation).'</p><table width="100%" style="font-size:14px;border-top:1px solid #dce5df;border-bottom:1px solid #dce5df">'.$rows.'</table><p style="margin:28px 0"><a href="'.$escape($url).'" style="display:inline-block;background:#145d50;color:white;padding:14px 20px;text-decoration:none;border-radius:6px">'.$escape($action).'</a></p><p style="font-size:12px;color:#53665e;line-height:1.5">Times are Asia/Manila (UTC+8). Open the system for the latest information.<br>Campus Facility &amp; Asset Booking System</p></td></tr></table></td></tr></table></body></html>';
        return ['subject'=>'CFABS: '.$title,'html'=>$html,'text'=>$text];
    }
}
