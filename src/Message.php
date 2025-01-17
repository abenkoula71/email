<?php declare(strict_types=1);
/*
 * This file is part of Aplus Framework Email Library.
 *
 * (c) Natan Felles <natanfelles@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Framework\Email;

use DateTime;
use LogicException;

/**
 * Class Message.
 *
 * @package email
 */
class Message
{
    /**
     * The Mailer instance.
     *
     * @var Mailer
     */
    protected Mailer $mailer;
    /**
     * The message boundary.
     *
     * @var string
     */
    protected string $boundary;
    /**
     * @var array<string,string>
     */
    protected array $headers = [
        'MIME-Version' => '1.0',
    ];
    /**
     * A list of attachments with Content-Disposition equals `attachment`.
     *
     * @var array<int,string> The filenames
     */
    protected array $attachments = [];
    /**
     * An associative array of attachments with Content-Disposition equals `inline`.
     *
     * @var array<string,string> The Content-ID's as keys and the filenames as values
     */
    protected array $inlineAttachments = [];
    /**
     * The plain text message.
     *
     * @var string|null
     */
    protected ?string $plainMessage = null;
    /**
     * The HTML message.
     *
     * @var string|null
     */
    protected ?string $htmlMessage = null;
    /**
     * An associative array used in the `To` header.
     *
     * @var array<string,string|null> The email addresses as keys and the optional
     * name as values
     */
    protected array $to = [];
    /**
     * An associative array used in the `Cc` header.
     *
     * @var array<string,string|null> The email addresses as keys and the optional
     * name as values
     */
    protected array $cc = [];
    /**
     * An associative array used in the `Bcc` header.
     *
     * @var array<string,string|null> The email addresses as keys and the optional
     * name as values
     */
    protected array $bcc = [];
    /**
     * An associative array used in the `Reply-To` header.
     *
     * @var array<string,string|null> The email addresses as keys and the optional
     * name as values
     */
    protected array $replyTo = [];
    /**
     * The values used in the `From` header.
     *
     * @var array<int,string|null> The email address as in the index 0 and the
     * optional name in the index 1
     */
    protected array $from = [];
    /**
     * The message Subject.
     *
     * @var string|null
     */
    protected ?string $subject = null;
    /**
     * The message Date.
     *
     * @var string|null
     */
    protected ?string $date = null;
    /**
     * The message X-Priority.
     *
     * @var int An integer from 1 to 5
     */
    protected int $priority = 3;
    /**
     * An associative array of Standard Headers.
     *
     * @var array<string,string> The lowercased names as keys and the Standard
     * Header name as values
     */
    protected static array $standardHeaders = [
        'bcc' => 'Bcc',
        'cc' => 'Cc',
        'content-type' => 'Content-Type',
        'date' => 'Date',
        'from' => 'From',
        'mime-version' => 'MIME-Version',
        'reply-to' => 'Reply-To',
        'subject' => 'Subject',
        'to' => 'To',
        'x-priority' => 'X-Priority',
    ];

    public function __construct()
    {
        $this->setBoundary();
    }

    public function __toString() : string
    {
        return $this->renderData();
    }

    /**
     * @param Mailer $mailer The Mailer instance
     *
     * @return static
     */
    public function setMailer(Mailer $mailer) : static
    {
        $this->mailer = $mailer;
        return $this;
    }

    protected function setBoundary() : void
    {
        $this->boundary = \bin2hex(\random_bytes(16));
    }

    protected function getBoundary() : string
    {
        return $this->boundary;
    }

    /**
     * @param string $name
     * @param string|null $value
     *
     * @return static
     */
    public function setHeader(string $name, ?string $value) : static
    {
        $this->headers[static::getHeaderName($name)] = $value;
        return $this;
    }

    public function getHeader(string $name) : ?string
    {
        return $this->headers[static::getHeaderName($name)] ?? null;
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders() : array
    {
        return $this->headers;
    }

    protected function renderHeaders() : string
    {
        $result = '';
        foreach ($this->getHeaders() as $name => $value) {
            if ($value !== null) {
                $name .= ': ' . $value;
            }
            $result .= $name . $this->mailer->getCrlf();
        }
        return $result;
    }

    protected function encodeHeader(string $name, string $value = null) : string
    {
        if ($value !== null) {
            $name .= ': ' . $value;
        }
        return \mb_encode_mimeheader($name, 'UTF-8', 'B', $this->mailer->getCrlf());
    }

    protected function prepareHeaders() : void
    {
        if ( ! $this->getHeader('Date')) {
            $this->setHeader('Date', \date('r'));
        }
        if ( ! $this->getHeader('Subject') && $this->getSubject() !== null) {
            $this->setHeader('Subject', $this->getSubject());
        }
        if ( ! $this->getHeader('From')) {
            $this->setHeader(
                'From',
                static::formatAddress($this->getFromAddress(), $this->getFromName())
            );
        }
        if ( ! $this->getHeader('To')) {
            $this->setHeader('To', static::formatAddressList($this->getTo()));
        }
        if ( ! $this->getHeader('Cc') && $this->getCc()) {
            $this->setHeader('Cc', static::formatAddressList($this->getCc()));
        }
        if ( ! $this->getHeader('Bcc') && $this->getBcc()) {
            $this->setHeader('Bcc', static::formatAddressList($this->getBcc()));
        }
        if ( ! $this->getHeader('Reply-To') && $this->getReplyTo()) {
            $this->setHeader('Reply-To', static::formatAddressList($this->getReplyTo()));
        }
        if ( ! $this->getHeader('Content-Type')) {
            $multipart = $this->getInlineAttachments() ? 'related' : 'mixed';
            $this->setHeader(
                'Content-Type',
                'multipart/' . $multipart . '; boundary="mixed-' . $this->getBoundary() . '"'
            );
        }
    }

    protected function renderData() : string
    {
        $this->prepareHeaders();
        $data = '--mixed-' . $this->getBoundary() . $this->mailer->getCrlf();
        $data .= 'Content-Type: multipart/alternative; boundary="alt-' . $this->getBoundary() . '"'
            . $this->mailer->getCrlf() . $this->mailer->getCrlf();
        $data .= $this->renderPlainMessage();
        $data .= $this->renderHtmlMessage();
        $data .= '--alt-' . $this->getBoundary() . '--'
            . $this->mailer->getCrlf() . $this->mailer->getCrlf();
        $data .= $this->renderAttachments();
        $data .= $this->renderInlineAttachments();
        $data .= '--mixed-' . $this->getBoundary() . '--';
        return $this->renderHeaders() . $this->mailer->getCrlf() . $data;
    }

    /**
     * @param string $message
     *
     * @return static
     */
    public function setPlainMessage(string $message) : static
    {
        $this->plainMessage = $message;
        return $this;
    }

    public function getPlainMessage() : ?string
    {
        return $this->plainMessage;
    }

    protected function renderPlainMessage() : ?string
    {
        $message = $this->getPlainMessage();
        return $message ? $this->renderMessage($message, 'text/plain') : null;
    }

    /**
     * @param string $message
     *
     * @return static
     */
    public function setHtmlMessage(string $message) : static
    {
        $this->htmlMessage = $message;
        return $this;
    }

    public function getHtmlMessage() : ?string
    {
        return $this->htmlMessage;
    }

    protected function renderHtmlMessage() : ?string
    {
        $message = $this->getHtmlMessage();
        return $message ? $this->renderMessage($message) : null;
    }

    protected function renderMessage(
        string $message,
        string $contentType = 'text/html'
    ) : string {
        $part = '--alt-' . $this->getBoundary() . $this->mailer->getCrlf();
        $part .= 'Content-Type: ' . $contentType . '; charset='
            . $this->mailer->getCharset() . $this->mailer->getCrlf();
        $part .= 'Content-Transfer-Encoding: base64'
            . $this->mailer->getCrlf() . $this->mailer->getCrlf();
        $part .= \chunk_split(\base64_encode($message)) . $this->mailer->getCrlf();
        return $part;
    }

    /**
     * @return array<int,string>
     */
    public function getAttachments() : array
    {
        return $this->attachments;
    }

    /**
     * @param string $filename The filename
     *
     * @return static
     */
    public function addAttachment(string $filename) : static
    {
        $this->attachments[] = $filename;
        return $this;
    }

    /**
     * @param string $filename The filename
     * @param string $cid The Content-ID
     *
     * @return static
     */
    public function setInlineAttachment(string $filename, string $cid) : static
    {
        $this->inlineAttachments[$cid] = $filename;
        return $this;
    }

    /**
     * @return array<string,string>
     */
    public function getInlineAttachments() : array
    {
        return $this->inlineAttachments;
    }

    protected function renderAttachments() : string
    {
        $part = '';
        foreach ($this->getAttachments() as $attachment) {
            if ( ! \is_file($attachment)) {
                throw new LogicException('Attachment file not found: ' . $attachment);
            }
            $filename = \pathinfo($attachment, \PATHINFO_BASENAME);
            $filename = \htmlspecialchars($filename, \ENT_QUOTES | \ENT_HTML5);
            $contents = (string) \file_get_contents($attachment);
            $part .= '--mixed-' . $this->getBoundary() . $this->mailer->getCrlf();
            $part .= 'Content-Type: application/octet-stream; name="' . $filename . '"'
                . $this->mailer->getCrlf();
            $part .= 'Content-Disposition: attachment; filename="' . $filename . '"'
                . $this->mailer->getCrlf();
            $part .= 'Content-Transfer-Encoding: base64'
                . $this->mailer->getCrlf() . $this->mailer->getCrlf();
            $part .= \chunk_split(\base64_encode($contents)) . $this->mailer->getCrlf();
        }
        return $part;
    }

    protected function renderInlineAttachments() : string
    {
        $part = '';
        foreach ($this->getInlineAttachments() as $cid => $filename) {
            if ( ! \is_file($filename)) {
                throw new LogicException('Inline attachment file not found: ' . $filename);
            }
            $contents = (string) \file_get_contents($filename);
            $part .= '--mixed-' . $this->getBoundary() . $this->mailer->getCrlf();
            $part .= 'Content-ID: ' . $cid . $this->mailer->getCrlf();
            $part .= 'Content-Type: ' . \mime_content_type($filename) . $this->mailer->getCrlf();
            $part .= 'Content-Disposition: inline' . $this->mailer->getCrlf();
            $part .= 'Content-Transfer-Encoding: base64' . $this->mailer->getCrlf() . $this->mailer->getCrlf();
            $part .= \chunk_split(\base64_encode($contents)) . $this->mailer->getCrlf();
        }
        return $part;
    }

    /**
     * @param string $subject
     *
     * @return static
     */
    public function setSubject(string $subject) : static
    {
        $this->subject = $subject;
        return $this;
    }

    public function getSubject() : ?string
    {
        return $this->subject;
    }

    /**
     * @param string $address
     * @param string|null $name
     *
     * @return static
     */
    public function addTo(string $address, string $name = null) : static
    {
        $this->to[$address] = $name;
        return $this;
    }

    /**
     * @return array<string,string|null>
     */
    public function getTo() : array
    {
        return $this->to;
    }

    /**
     * Add Carbon Copy email address.
     *
     * @param string $address
     * @param string|null $name
     *
     * @return static
     */
    public function addCc(string $address, string $name = null) : static
    {
        $this->cc[$address] = $name;
        return $this;
    }

    /**
     * @return array<string,string|null>
     */
    public function getCc() : array
    {
        return $this->cc;
    }

    /**
     * @return array<int,string>
     */
    public function getRecipients() : array
    {
        $recipients = \array_replace($this->getTo(), $this->getCc());
        return \array_keys($recipients);
    }

    /**
     * Add Blind Carbon Copy email address.
     *
     * @param string $address
     * @param string|null $name
     *
     * @return static
     */
    public function addBcc(string $address, string $name = null) : static
    {
        $this->bcc[$address] = $name;
        return $this;
    }

    /**
     * @return array<string,string|null>
     */
    public function getBcc() : array
    {
        return $this->bcc;
    }

    /**
     * @param string $address
     * @param string|null $name
     *
     * @return static
     */
    public function addReplyTo(string $address, string $name = null) : static
    {
        $this->replyTo[$address] = $name;
        return $this;
    }

    /**
     * @return array<string,string|null>
     */
    public function getReplyTo() : array
    {
        return $this->replyTo;
    }

    /**
     * @param string $address
     * @param string|null $name
     *
     * @return static
     */
    public function setFrom(string $address, string $name = null) : static
    {
        $this->from = [$address, $name];
        return $this;
    }

    /**
     * @return array<int,string|null>
     */
    public function getFrom() : array
    {
        return $this->from;
    }

    public function getFromAddress() : ?string
    {
        return $this->from[0] ?? null;
    }

    public function getFromName() : ?string
    {
        return $this->from[1] ?? null;
    }

    /**
     * @param DateTime|null $datetime
     *
     * @return static
     */
    public function setDate(DateTime $datetime = null) : static
    {
        $this->date = $datetime ? $datetime->format('r') : \date('r');
        $this->setHeader('Date', $this->date);
        return $this;
    }

    public function getDate() : ?string
    {
        return $this->date;
    }

    /**
     * @param int $priority from 1 to 5
     *
     * @return static
     */
    public function setPriority(int $priority) : static
    {
        $this->priority = $priority;
        $this->setHeader('X-Priority', (string) $priority);
        return $this;
    }

    public function getPriority() : int
    {
        return $this->priority;
    }

    protected static function getHeaderName(string $header) : string
    {
        return static::$standardHeaders[\strtolower($header)] ?? $header;
    }

    protected static function formatAddress(string $address, string $name = null) : string
    {
        return $name !== null ? '"' . $name . '" <' . $address . '>' : $address;
    }

    /**
     * @param array<string,string|null> $addresses
     *
     * @return string
     */
    protected static function formatAddressList(array $addresses) : string
    {
        $data = [];
        foreach ($addresses as $address => $name) {
            $data[] = static::formatAddress($address, $name);
        }
        return \implode(', ', $data);
    }
}
