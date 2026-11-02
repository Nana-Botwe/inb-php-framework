# File uploads & compression

Uploads are validated by their **real content**, stored under **random names**, and **compressed automatically**.

## Uploading a file

```blade
<form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data">
    @csrf
    <input type="file" name="document" required>
    @error('document') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
    <button>Upload</button>
</form>
```

```php
public function store(Request $request): Response
{
    $request->validate(['document' => 'required|file|mimes:pdf,docx,xlsx|max:5120']);   // KB

    $path = $request->file('document')->store('documents');          // private disk
    // or: ->store('avatars', 'public') for files that need a public URL

    Document::create(['path' => $path, 'user_id' => Auth::id()]);

    return back()->with('success', 'Uploaded.');
}
```

## What happens to every upload

1. **Size check** against `uploads.max_kb` (and PHP's `upload_max_filesize`).
2. **Real type detection** from the file bytes (`finfo`). The file name and the browser's claimed type are ignored. Only types in the safe list can be stored (images, PDF, Office files, CSV, TXT, ZIP, MP3, MP4).
3. **Random file name** (`k3J9...x2.webp`), with an extension taken from the real type. `shell.php.jpg` or a renamed script can never become runnable.
4. **Images** are decoded and re-encoded with GD:
   - resized to fit 1920×1920 (aspect ratio kept)
   - converted to **WebP** at quality 82 (visually lossless; typically 70–95% smaller than phone photos)
   - EXIF/GPS metadata stripped (privacy) — orientation is applied first so photos aren't sideways
   - re-encoding destroys hidden payloads ("polyglot" files with PHP/JS inside an image)
   - images over 40 megapixels are refused (decompression-bomb protection)
5. **Documents** on private disks are compressed **losslessly** with the best algorithm available:

| Algorithm | When used | Speed / ratio |
|---|---|---|
| **zstd** (level 3) | if the `zstd` PHP extension is installed | fastest, excellent ratio |
| **Brotli** (level 5) | if the `brotli` extension is installed | fast, better ratio than gzip |
| **gzip / deflate** (level 6) | always available (zlib) | fast, good ratio — text/CSV often shrinks 5–20× |

Formats that are **already compressed** (JPG, PNG, WebP, PDF, ZIP, DOCX, XLSX, PPTX, MP3, MP4) are not compressed again: it would cost CPU and can make them bigger. A file is only stored compressed if that actually makes it smaller.

> **Note:** No algorithm can shrink every file — compressed formats are already near their limit. The framework picks the best trade-off automatically for each file.

## Reading compressed files

Compression is transparent:

```php
Storage::disk('local')->read($path);          // original contents
return Storage::disk('local')->download($path, 'report.csv');   // decompressed + original name
$file->compressionStats();                    // ['original_size' => ..., 'size' => ...]
```

## Disks

| Disk | Folder | Web access |
|---|---|---|
| `local` (default) | `storage/app/private` | **none** — serve through a controller that checks permissions |
| `public` | `public/uploads` | yes, at `/uploads/...` — PHP/scripts can't run there |

```php
use Core\Storage;

Storage::disk('public')->url($path);           // "/my-shop/uploads/avatars/abc.webp"
Storage::disk('local')->put('notes/a.txt', 'hello');
Storage::disk('local')->get('notes/a.txt');
Storage::disk('local')->exists($path);
Storage::disk('local')->delete($path);
Storage::disk('local')->files('notes');
Storage::disk('local')->size($path);
```

Paths containing `..`, absolute paths and drive letters are rejected, so a path can never escape its disk.

### Serving private files safely

```php
public function download(Document $document): Response
{
    $this->authorize($document->user_id === Auth::id());

    return Storage::disk('local')->download($document->path, 'document.pdf');
}
```

## Configuration

```php
// config/uploads.php
'max_kb' => 10240,
'compress' => true,
'algorithm' => 'auto',        // auto | zstd | brotli | gzip
'images' => [
    'optimize' => true,
    'format' => 'webp',       // webp | jpg | png | original
    'quality' => 82,
    'max_width' => 1920,
    'max_height' => 1920,
],
```

## How public/uploads is protected

`public/uploads/.htaccess` removes every script handler, turns the PHP engine off, refuses script-like extensions (`.php`, `.phtml`, `.phar`, `.html`, `.svg`, `.js`...) and sends `X-Content-Type-Options: nosniff` plus a sandboxing CSP. Even if a script got in, it could not run.
