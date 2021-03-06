<?php

namespace App\Http\Controllers;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\HitLogger;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(){
        $categories = Category::all();
        $comments = Comment::all();
        $articleCategories = Article::all()->groupBy('category_name');
        $hitCountries = HitLogger::all()->groupBy('country');
        return view('backend.dashboard', compact('hitCountries', 'articleCategories'));
    }
}
