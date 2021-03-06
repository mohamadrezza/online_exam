<?php

namespace App\Http\Controllers;

use App\Exam;
use App\Lesson;
use App\Question;
use Carbon\Carbon;
use App\ExamSession;
use App\Helpers\Constants;
use Illuminate\Http\Request;
use App\Services\Exam\ExamService;
use App\Http\Resources\QuizResource;
use App\Http\Resources\ResultResource;
use App\Jobs\InsertAnswers;
use App\QuestionAnswers;
use App\StudentAnswer;
use App\StudentResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExamController extends Controller
{
    function create($lessonId, Request $request)
    {
        $request->validate([
            'duration' => 'required',
            'title' => 'required',
            'startedAt' => 'required'
        ]);
        Lesson::where('id', $lessonId)
            ->where('teacher_id', Auth::id())
            ->firstOrFail();
        try {
            Exam::create([
                'lesson_id' => $lessonId,
                'teacher_id' => Auth::id(),
                'duration' => $request->duration,
                "description" => $request->description,
                'started_at' => Carbon::createFromTimestamp($request->startedAt),
                'title' => $request->title,
                'finished_at' => Carbon::createFromTimestamp($request->startedAt)->addMinutes($request->duration)
            ]);
            return $this->respondWithTemplate(true, [], 'امتحان ثبت شد');
        } catch (\Exception $e) {
            return $this->respondWithTemplate(false, [], $e->getMessage());
        }
    }
    function selectExamQuestions($lessonId, $examId, Request $request)
    {

        $request->validate([
            'questions' => 'required|array'
        ]);

        $exam = Exam::where('lesson_id', $lessonId)
            ->where('id', $examId)->firstOrFail();
        try {
            $questionIds = Question::whereIn('id', $request->questions)
                ->where('is_accepted', 1)
                ->pluck('id');

            $exam->questions()->syncWithoutDetaching($questionIds);
            return $this->respondWithTemplate(true, [], 'سوالات امتحان ثبت شد');
        } catch (\Exception $e) {
            return $this->respondWithTemplate(false, [], $e->getMessage());
        }
    }


    function getAll(Request $request)
    {
        $exams = Exam::query()->with('teacher');
        try {
            $exams->when($request->title,function($q) use($request){
                return $q->where('title','like','%'. $request->title. '%');
        });
        $data= $exams->orderBy('created_at',$request->order??'desc')
        ->paginate($request->perPage??20);
        return $this->respondWithTemplate(true, $data);
        }
        catch (\Exception $e) {
            return $this->respondWithTemplate(false, [], $e->getMessage());
        }
    }

    //teacher only
    function getById()
    {
        $exam = Exam::whereId(1)
            ->with(['questions.answers', 'lesson'])
            ->firstOrFail();
        $data = QuizResource::collection($exam);

        return $this->respondWithTemplate(true, $data);
    }
    function start($examId)
    {
        $exam = Exam::whereId($examId)
            ->with(['questions.answers', 'lesson'])
            ->firstOrFail();
        try {
            $service = new ExamService($exam, Auth::id());
            $service->checkExamAvailability();
            ExamSession::create([
                'student_id' => Auth::id(),
                'exam_id' => $examId,
                'started_at' => now()
            ]);
            $data = new QuizResource($exam);
            return $this->respondWithTemplate(true, $data);
        } catch (\Exception $e) {
            return $this->respondWithTemplate(false, [], $e->getMessage());
        }
    }
    function finish($examId, Request $request)
    {
        $request->validate([
            'answers' => 'required|array'
        ]);
        $exam = Exam::whereId($examId)
            ->firstOrFail();

        $examSession = ExamSession::where('exam_id', $examId)
            ->where('student_id', Auth::id())
            ->firstOrFail();

        try {
            $service = new ExamService($exam, Auth::id());
            $service->canUserFinishExam();
            $examSession->update([
                'finished_at' => now()
            ]);

            dispatch(new InsertAnswers($request->answers, Auth::id(), $examId));
            return $this->respondWithTemplate(true, [], 'امتحان شما با موفقیت ثبت شد');
        } catch (\Exception $e) {
            return $this->respondWithTemplate(false, [], $e->getMessage());
        }
    }

    public function result($examId)
    {
        try {
            $userId = Auth::id();
            $result = StudentResult::where('student_id', $userId)
                ->with('exam.teacher')
                ->where('exam_id', $examId)->firstOrFail();
            $data = new ResultResource($result);
            return $this->respondWithTemplate(true, $data);
        } catch (\Exception $e) {
            return $this->respondWithTemplate(false, [], $e->getMessage());
        }
    }
    public function allResults()
    {
        try {
            $results = StudentResult::where('student_id', auth()->id())->paginate(10);
            $data =  ResultResource::collection($results);
            return $this->respondWithTemplate(true, $data);
        } catch (\Throwable $e) {
            return $this->respondWithTemplate(false, [], $e->getMessage());
        }
    }
}
