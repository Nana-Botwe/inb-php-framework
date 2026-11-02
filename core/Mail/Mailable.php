<?php

declare(strict_types=1);

namespace Core\Mail;

use Core\App;

/**
 * An email. Create one with:  php console make:mail OrderShipped
 *
 *   class OrderShipped extends Mailable {
 *       public function __construct(public Order $order) {}
 *       public function build(): void {
 *           $this->subject('Your order has shipped')->view('emails.order-shipped', ['order' => $this->order]);
 *       }
 *   }
 *   Mail::to($user->email)->send(new OrderShipped($order));
 */
abstract class Mailable
{
    public string $subject = '';

    public ?string $view = null;

    /** @var array<string, mixed> */
    public array $viewData = [];

    public ?string $text = null;

    /** @var array<int, array{address: string, name: string}> */
    public array $to = [];

    /** @var array<int, array{address: string, name: string}> */
    public array $cc = [];

    /** @var array<int, array{address: string, name: string}> */
    public array $bcc = [];

    /** @var array{address: string, name: string}|null */
    public ?array $replyTo = null;

    /** @var array<int, array{path: string, name: string}> */
    public array $attachments = [];

    abstract public function build(): void;

    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function view(string $view, array $data = []): static
    {
        $this->view = $view;
        $this->viewData = $data;

        return $this;
    }

    /** Plain-text body (used as-is, or as the alternative part next to the HTML view). */
    public function text(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function replyTo(string $address, string $name = ''): static
    {
        $this->replyTo = ['address' => $address, 'name' => $name];

        return $this;
    }

    public function attach(string $path, ?string $name = null): static
    {
        $this->attachments[] = ['path' => $path, 'name' => $name ?? basename($path)];

        return $this;
    }

    public function renderHtml(): ?string
    {
        return $this->view === null ? null : App::instance()->view->render($this->view, $this->viewData);
    }
}
