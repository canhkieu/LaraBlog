<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Article;
use App\Models\Category;
use App\Models\HitLogger;
use App\Models\Keyword;
use App\Models\User;
use App\Mail\NotifySubscriberForNewArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class ArticleController extends Controller
{
    public function index(){
        $articles =  Article::where('is_published', 1)->where('is_deleted', 0)
            ->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        return view('frontend.articles', compact('articles'));
    }

    public function show($articleId){
        $clientIP = $_SERVER['REMOTE_ADDR'];
        $article = Article::where('id', $articleId)
            ->where('is_published', 1)
            ->where('is_deleted', 0)
            ->with(['category', 'keywords', 'comments' => function($comments){
                $comments->where('is_published', 1)->orderBy('created_at', 'desc');
            }])->first();
        if(is_null($article)){
            return redirect()->route('home')->with('warningMsg', 'Article not found');
        }
        try{
            $address = Address::firstOrCreate(['ip' => $clientIP]);
            $hitLogger = HitLogger::where('article_id', $articleId)->where('address_id', $address->id)->first();
            if(is_null($hitLogger)){
                HitLogger::create(['article_id' => $articleId, 'address_id' => $address->id, 'count' => 1]);
                $article->increment('hit_count');
            }else{
                $hitLogger->update(['count'=> ++$hitLogger->count]);
            }
        }catch(\PDOException $e){
            return redirect()->route('home')->with('errorMsg', $this->getMessage($e));
        }
        $relatedArticles = Article::where('category_id', $article->category->id)
            ->where('id', '!=', $article->id)
            ->where('is_published', 1)
            ->where('is_deleted', 0)
            ->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get();

        return view('frontend.article', compact('article', 'relatedArticles'));
    }

    public function edit($articleId){
        $article = Article::find($articleId);
        if(is_null($article)){
            return redirect()->route('home')->with('errorMsg', 'Article not found');
        }

        if($this->hasArticleAuthorization(Auth::user(), $article)){
            return redirect()->route('home')->with('errorMsg', 'Unauthorized request');
        }
        $categories = Category::where('is_active', 1)->get();
        return view('backend.article_edit', compact('categories', 'article'));
    }

    public function update(Request $request, $articleId){
        $article = Article::find($articleId);
        if(is_null($article)){
            return redirect()->route('home')->with('errorMsg', 'Article not found');
        }

        if($this->hasArticleAuthorization(Auth::user(), $article)){
            return redirect()->route('home')->with('errorMsg', 'Unauthorized request');
        }
        $updatedArticle = $request->only(['heading', 'content', 'category_id', 'language']);
        $keywordsToAttach = array_unique(explode(' ',$request->get('keywords')));
        try{
            $article->update($updatedArticle);
            //remove all keywords then add all keywords from input
            $article->keywords()->detach();
            foreach($keywordsToAttach as $keywordToAttach){
                $newKeyword = Keyword::firstOrCreate(['name' => $keywordToAttach]);
                $article->keywords()->attach($newKeyword->id);
            }
        }catch(\PDOException $e){
            return redirect()->back()->with('errorMsg', $this->getMessage($e));
        }

        return redirect()->route('admin-articles')->with('successMsg', 'Article updated');
    }

    public function create(){
        $categories = Category::where('is_active', 1)->get();
        return view('backend.article_create', compact('categories'));
    }

    public function store(Request $request){
        $clientIP = $_SERVER['REMOTE_ADDR'];

        $newArticle = $request->only(['heading', 'content', 'category_id', 'language']);
        $newAddress = ['ip' => $clientIP];

        try{
            //Create new address
            $newAddress = Address::create($newAddress);
            //Create new article
            $newArticle['address_id'] = $newAddress->id;
            $newArticle['published_at'] = new \DateTime();
            $newArticle['user_id'] = Auth::user()->id;
            $newArticle = Article::create($newArticle);
            //add keywords
            $keywordsToAttach = array_unique(explode(' ',$request->get('keywords')));
            foreach($keywordsToAttach as $keywordToAttach){
                $newKeyword = Keyword::firstOrCreate(['name' => $keywordToAttach]);
                $newArticle->keywords()->attach($newKeyword->id);
            }
            //Notify all subscriber about the new article
            Mail::to(User::getSubscribedUsers()->pluck('email')->toArray())
                ->queue(new NotifySubscriberForNewArticle($newArticle)); 
        }catch(\PDOException $e){
            return redirect()->back()->with('errorMsg', $this->getMessage($e));
        }

        return redirect()->route('admin-articles')->with('successMsg', 'Article published successfully!');
    }

    public function togglePublish($articleId){
        $article = Article::find($articleId);
        if(is_null($article)){
            return redirect()->route('home')->with('errorMsg', 'Article not found');
        }

        if($this->hasArticleAuthorization(Auth::user(), $article)){
            return redirect()->route('home')->with('errorMsg', 'Unauthorized request');
        }
        try{
            $article->update([
                'is_published' => !$article->is_published,
                'published_at' => new \DateTime(),
            ]);
        }catch(\PDOException $e){
            return redirect()->back()->with('errorMsg', $this->getMessage($e));
        }
        return redirect()->route('admin-articles')->with('successMsg', 'Article updated');
    }

    public function search(Request $request){
        $this->validate($request, ['query_string' => 'required']);

        $queryString = $request->get('query_string');
        $keywords = Keyword::where('name', 'LIKE', "%$queryString%")->where('is_active', 1)->get();
        $articleIDsByKeywords = Keyword::getArticleIDs($keywords);

        $articles = Article::where('is_published', 1)
            ->where('is_deleted', 0)
            ->whereIn('id', $articleIDsByKeywords)
            ->where('heading', 'LIKE', "%$queryString%")
            ->orWhere('content', 'LIKE', "%$queryString%")
            ->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $articles->setPath(url("search/?query_string=$queryString"));

        $searched = new \stdClass();
        $searched->articles = $articles;
        $searched->query = $queryString;
        return view('frontend.search_result', compact('searched'));
    }

    public function adminArticle(){
        $articles =  Article::where('is_deleted', 0)
            ->with('category', 'keywords', 'user')
            ->orderBy('id', 'desc')
            ->get();
        if(Auth::user()->hasRole(['author'])){
            $articles = $articles->where('user_id', Auth::user()->id);
        }
        return view('backend.articleList', compact('articles'));
    }

    public function destroy($articleId){
        $article = Article::find($articleId);
        if(is_null($article)){
            return redirect()->route('home')->with('errorMsg', 'Article not found');
        }

        if($this->hasArticleAuthorization(Auth::user(), $article)){
            return redirect()->route('home')->with('errorMsg', 'Unauthorized request');
        }
        try{
            Article::where('id', $articleId)->update(['is_deleted' => 1]);
        }catch (\PDOException $e){
            return redirect()->back()->with('errorMsg', $this->getMessage($e));
        }
        return redirect()->route('admin-articles')->with('successMsg', 'Article deleted');
    }

    private function hasArticleAuthorization($user, $article){
        return $user->hasRole(['author']) && $article->user_id != $user->id;
    }
}
