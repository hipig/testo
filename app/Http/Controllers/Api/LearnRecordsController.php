<?php

namespace App\Http\Controllers\Api;

use App\Events\LearnRecordItemStored;
use App\Events\LearnRecordSubmitted;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LearnRecordTestStoreRequest;
use App\Http\Requests\Api\LearnRecordUpdateRequest;
use App\Http\Resources\LearnRecordShowResource;
use App\Http\Resources\LearnRecordResource;
use App\Models\Bank;
use App\Models\LearnRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearnRecordsController extends Controller
{
    public function index(Request $request)
    {
        $learnRecords = optional($request->user('api'))
            ->records()
            ->with('bank:id,subject_id,title', 'bank.subject:id,title')
            ->inSubject($request->subject_pid)
            ->inBank($request->subject_id)
            ->betweenDate($request->date)
            ->inType($request->type)
            ->orderBy('created_at', 'desc')
            ->paginate($request->page_size ?? config('api.page_size'));

        return LearnRecordResource::collection($learnRecords);
    }

    public function show(Request $request, LearnRecord $record)
    {
        if ($record->is_end) {
            throw new InvalidRequestException('考试已结束！');
        }

        if ($record->is_group) {
            $record->load('user.recordItems', 'bank.groups', 'bank.groups.items', 'bank.groups.items.collects');
        } else {
            $record->load('user.recordItems', 'bank.items', 'bank.items.collects');
        }

        return LearnRecordShowResource::make($record);
    }

    public function showResult(Request $request, LearnRecord $record)
    {
        if (!$record->is_end) {
            throw new InvalidRequestException('请先交卷再来查看解析！');
        }

        $record->load('items');
        $record->withResult = true;
        return LearnRecordShowResource::make($record);
    }

    public function storeTest(LearnRecordTestStoreRequest $request)
    {
        $mode = $request->mode ?? LearnRecord::QUIZ_TEST;
        $number = $request->number;

        $bank = Bank::query()->findOrFail($request->bank_id);
        $user = optional($request->user('api'));
        $hasChildren = $bank->has_children;

        $bankIds = $hasChildren ? $bank->children()->pluck('id') : [$bank->id];
        $bankItems = $hasChildren ? $bank->childrenItems() : $bank->items();
        switch ($request->range) {
            case 'undone':
                $doneIds = $user->recordItems()->whereIn('learn_record_items.bank_id', $bankIds)->pluck('bank_item_id');
                $bankItems->whereNotIn('bank_items.id', $doneIds);
                break;
            case 'done':
                $doneIds = $user->recordItems()->whereIn('learn_record_items.bank_id', $bankIds)->pluck('bank_item_id');
                $bankItems->whereIn('bank_items.id', $doneIds);
                break;
            case 'error':
                $errorIds = $user->errors()->whereIn('bank_id', $bankIds)->pluck('bank_item_id');
                $bankItems->whereIn('bank_items.id', $errorIds);
                break;
        }

        if ($type = $request->type) {
            $bankItems->where('bank_items.question_type', $type);
        }

        $idPluck = $bankItems->pluck('bank_items.id');
        $count = $idPluck->count();
        $ids = $idPluck->random($number >= $count ? $count : $number);

        $record = optional($request->user('api'))
            ->records()
            ->create([
                'bank_id' => $bank->id,
                'quiz_mode' => $mode,
                'type' => $bank->type,
                'question_ids' => $ids->toArray(),
                'total_count' => $ids->count()
            ]);

        $bank->increment('done_count');

        return LearnRecordResource::make($record);
    }

    public function storeExam(Request $request)
    {
        $this->validate($request, ['bank_id' => 'required'], [], ['bank_id' => '题库']);

        $bank = Bank::query()->findOrFail($request->bank_id);

        $record = optional($request->user('api'))
            ->records()
            ->create([
                'bank_id' => $bank->id,
                'quiz_mode' => LearnRecord::QUIZ_EXAM,
                'type' => $bank->type,
                'total_count' => $bank->total_count
            ]);

        $bank->increment('done_count');

        return LearnRecordResource::make($record);
    }

    public function storeDailyTest(Request $request)
    {
        $this->validate($request, ['bank_id' => 'required'], [], ['bank_id' => '题库']);

        $bank = Bank::query()->findOrFail($request->bank_id);
        $query = optional($request->user('api'))->records();

        $record = (clone $query)->where('bank_id', $bank->id)
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->first();
        if (!$record) {
            $record = (clone $query)->create([
                'bank_id' => $bank->id,
                'quiz_mode' => LearnRecord::QUIZ_TEST,
                'type' => $bank->type,
                'total_count' => $bank->total_count
            ]);

            $bank->increment('done_count');
        }

        return LearnRecordResource::make($record);
    }

    public function update(LearnRecordUpdateRequest $request, LearnRecord $record)
    {
        if ($record->is_end) {
            throw new InvalidRequestException('禁止重复交卷！');
        }

        $record = DB::transaction(function () use ($record, $request) {
            $record->done_time = $request->done_time;
            $record->is_end = $request->type && $request->type == 'end';
            $record->save();

            $items = $request->items;
            foreach ($items as $data) {
                $selectData = collect($data)->only('record_id', 'bank_id', 'bank_item_id', 'question_id')->toArray();

                $recordItem = $record->items()->updateOrCreate(
                    $selectData,
                    $data
                );

                event(new LearnRecordItemStored($recordItem));
            }

            return $record;
        });

        event(new LearnRecordSubmitted($record));

        return LearnRecordResource::make($record);
    }

    public function destroy(Request $request, LearnRecord $record)
    {
        $this->authorize('own', $record);

        $record->delete();

        return response(null, 204);
    }
}
