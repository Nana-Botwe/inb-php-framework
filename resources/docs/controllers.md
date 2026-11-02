# Controllers

Controllers group the logic for related pages. They live in `app/Http/Controllers` and extend `App\Http\Controllers\Controller`.

```bash
php console make:controller PostController            # one index() method
php console make:controller PostController --resource # all 7 CRUD methods
php console make:controller PostApiController --api  # JSON CRUD (without create/edit)
```

## A typical controller

```php
namespace App\Http\Controllers;

use App\Models\Post;
use Core\Auth;
use Core\Request;
use Core\Response;

final class PostController extends Controller
{
    public function index(): Response
    {
        return $this->view('posts.index', ['posts' => Post::latest()->paginate(10)]);
    }

    public function show(Post $post): Response
    {
        return $this->view('posts.show', ['post' => $post]);
    }

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'title' => 'required|string|max:200|no_html',
            'body' => 'required|string',
        ]);

        $post = Post::create($data + ['user_id' => Auth::id()]);

        return $this->redirectToRoute('posts.show', ['post' => $post])->with('success', 'Post published.');
    }

    public function destroy(Post $post): Response
    {
        $this->authorize($post->user_id === Auth::id(), 'You can only delete your own posts.');
        $post->delete();

        return $this->back()->with('success', 'Post deleted.');
    }
}
```

## Helpers on the base controller

| Method | Returns |
|---|---|
| `$this->view('name', $data, $status)` | HTML from a Blade view |
| `$this->json($data, $status)` | JSON response |
| `$this->redirect('/path')` | redirect to an app path |
| `$this->redirectToRoute('name', $params)` | redirect to a named route |
| `$this->back()` | redirect to the previous page |
| `$this->validate($request, $rules)` | validated data (or redirect back with errors) |
| `$this->authorize($condition, $message)` | stops with 403 (and logs it) when false |

You can also return a string (HTML), an array or a model (JSON).

## Dependency injection

Any class type-hinted in the constructor or a method is created by the [service container](helpers#service-container):

```php
public function __construct(private readonly InvoiceService $invoices) {}

public function download(Request $request, int $id, PdfRenderer $pdf): Response { ... }
```

Order doesn't matter: `Request` is injected by type, route parameters by name, models by route model binding.

## Single-action controllers

```php
final class ShowDashboard extends Controller
{
    public function __invoke(): Response { ... }
}

$router->get('/dashboard', ShowDashboard::class);
```
