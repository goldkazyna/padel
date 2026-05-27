<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Services\ClubClientImporter;
use Illuminate\Http\Request;

class ClientImportController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function showForm()
    {
        $club = $this->getClub();
        abort_unless($club, 403);
        $report = session('import_report');
        return view('club.clients.import', compact('club', 'report'));
    }

    public function import(Request $request, ClubClientImporter $importer)
    {
        $club = $this->getClub();
        abort_unless($club, 403);

        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv,txt|max:10240',
        ], [
            'file.required' => 'Выберите файл',
            'file.mimes' => 'Поддерживаются форматы: xls, xlsx, csv',
            'file.max' => 'Файл слишком большой (макс. 10 МБ)',
        ]);

        $path = $request->file('file')->getRealPath();
        $report = $importer->import($club->id, $path);

        return redirect()
            ->route('club.clients.import.form')
            ->with('import_report', $report);
    }
}
