<?php

namespace App\Http\Controllers;

use App\Category;
use App\Post;
use App\Tag;
use Auth;
use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;
use Session;


class PostsController extends Controller
{
    public function index()
    {
        return view('admin.posts.index')->with('posts', Post::all());
    }


    public function create()
    {
        $this->deleteTemp();
        $categories = Category::all();
        if ($categories->count() == 0) {
            Session::flash('info', 'You Must have Choose At least One Category');

            return redirect()->back();
        }
        return view('admin.posts.create')->with('categories', $categories)
            ->with('tags', Tag::all());
    }


    public function checkImageInContent($body, $postId)
    {
        $dom = new DOMDocument();
        @$dom->loadHtml(mb_convert_encoding($body, 'HTML-ENTITIES', "UTF-8"), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $images = $dom->getElementsByTagName('img');

        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            if (preg_match('/\/uploads\/temp/', $src) && preg_match('/http:\/\//', $src)) {
                $split = explode('/', $src);
                $name = $split[count($split) - 1];
                Storage::disk('real')->move('uploads/temp/' . $name, "uploads/body/{$postId}/{$name}");
                $newSrc = str_replace("uploads/temp/", "uploads/body/{$postId}/", $src);
                $body = str_replace($src, $newSrc, $body);
            } // <!--endif
        } // <!--endforeach

        // delete old image
        $files = Storage::disk('real')->allFiles('/uploads/body/' . $postId);
        if (count($files) > 0) {
            foreach ($files as $file) {
                if (strpos($body, $file) === false) {
                    Storage::disk('real')->delete($file);
                }
            }
        }

        return $body;
    }


    public function store(Request $request)
    {
        $this->validate($request, [

            'title' => 'required',
            'featured' => 'required|image',
            'body' => 'required',
            'category_id' => 'required',
            'tags' => 'required'

        ]);

        $post = Post::create([


            'title' => $request->title,
            'category_id' => $request->category_id,
            'slug' => str_slug($request->title) . '-' . $this->generateRandom(4),
            'user_id' => Auth::id()


        ]);

        $path = '/uploads/posts/';
        $post->featured = $path . $this->uploadImage($request->featured, $path);
        $post->body = $this->checkImageInContent($request->body, $post->id);
        $post->save();
        $post->tags()->attach($request->tags);


        Session::flash('success', 'Your post Created Succesfully');
        $this->deleteTemp();
        return redirect()->back();
    }


    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        $this->deleteTemp();
        $posts = Post::find($id);

        return view('admin.posts.edit')->with('posts', $posts)
            ->with('categories', Category::all())
            ->with('tags', Tag::all());
    }


    public function update(Request $request, $id)
    {


        $this->validate($request, [

            'title' => 'required',
            'body' => 'required',
            'category_id' => 'required'


        ]);

        $posts = Post::find($id);

        if ($request->hasfile('featured')) {
            $path = '/uploads/posts/';
            $this->deleteImageFeatureOfPost($posts->featured);
            $posts->featured = $path . $this->uploadImage($request->featured, $path);
        }


        $posts->title = $request->title;
        $posts->body = $this->checkImageInContent($request->body, $id);
        $posts->category_id = $request->category_id;
        $posts->save();
        $posts->tags()->sync($request->tags);

        $this->deleteTemp();
        Session::flash('success', 'You succesfully updated a Post.');
        return redirect()->route('posts');


    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return Response
     */
    public function destroy($id)
    {
        $posts = Post::find($id);
        $posts->delete();
        Session::flash('success', 'You succesfully deleted a Post.');
        return redirect()->back();
    }

    public function trashed()
    {

        $posts = Post::onlyTrashed()->get();


        return view('admin.posts.trashed')->with('posts', $posts);

    }

    public function kill($id)
    {
        $posts = Post::withTrashed()->where('id', $id)->first();
        $this->deleteImageFeatureOfPost($posts->featured);
        File::deleteDirectory(public_path("uploads/body/{$posts->id}"));
        $posts->forceDelete();
        Session::flash('success', 'You succesfully deleted a Post Permanently.');
        return redirect()->back();

    }

    public function restore($id)

    {
        $posts = Post::withTrashed()->where('id', $id)->first();
        $posts->restore();
        Session::flash('success', 'You succesfully Restore a Post.');
        return redirect()->route('posts');


    }

    public function generateRandom($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function upload(Request $request)
    {
        if ($request->has('upload')) {
            $img = $request->file('upload');
            $name = $this->uploadImage($img, '/uploads/temp/');

            return response()->json([
                'status' => 200,
                'url' => url('/uploads/temp/' . $name),
            ]);
        }
    }

    public function uploadImage($image, $path, $isResize = true)
    {
        if ($isResize) {
            $filename = time() . $this->generateRandomString() . $image->getClientOriginalName();
            $width = 750; // your max width
            $height = 750; // your max height
            $image_resize = Image::make($image->getRealPath());
            if ($image_resize->height() > $height || $image_resize->width() > $width) {
                $image_resize->height() > $image_resize->width() ? $width = null : $height = null;
                $image_resize->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }
            $image_resize->save(public_path($path . $filename));
            return $filename;
        } else {
            $filename = time() . $this->generateRandomString() . $image->getClientOriginalName();
            $image_resize = Image::make($image->getRealPath());
            $image_resize->save(public_path($path . $filename));
            return $filename;
        }
    }

    public function deleteTemp()
    {
        $files = Storage::disk('real')->allFiles('/uploads/temp');
        Storage::disk('real')->delete($files);
    }

    public function deleteImageFeatureOfPost($path)
    {
        $split = explode('/', $path);
        $name = $split[count($split) - 1];
        if (Storage::disk('real')->exists('uploads/posts/'. $name)) {
            Storage::disk('real')->delete('uploads/posts/'. $name);
        }
    }
}
