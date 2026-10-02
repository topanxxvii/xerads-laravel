<?php

use Illuminate\Http\Request;
use XerAds\CmsBridge\Data\IncomingArticle;

function requestWithBody(string $body, string $query = '', string $contentType = 'application/json'): Request
{
    return Request::create('/api/xerads/articles'.($query !== '' ? '?'.$query : ''), 'POST', server: ['CONTENT_TYPE' => $contentType], content: $body);
}

it('reads a JSON object from the raw body', function () {
    expect(IncomingArticle::payloadFromRequest(requestWithBody(" \n{\"title\":\"Judul\"}")))->toBe(['title' => 'Judul'])
        ->and(IncomingArticle::payloadFromRequest(requestWithBody('{}')))->toBe([]);
});

it('treats anything but a JSON object as no payload', function (string $body) {
    expect(IncomingArticle::payloadFromRequest(requestWithBody($body)))->toBeNull();
})->with(['[]', '[1,2]', '"text"', '42', 'null', 'title=x', '{"title":', '']);

it('never reads the query string or form input', function () {
    $request = requestWithBody('{"title":"Signed","content":"<p>Signed.</p>"}', 'title=Unsigned&cms_post_id=1&test=1', 'text/plain');
    $article = IncomingArticle::fromRequest($request);

    expect($article->title)->toBe('Signed')
        ->and($article->remoteId)->toBeNull()
        ->and(IncomingArticle::isConnectionTest($request))->toBeFalse();
});

it('coerces wrong types instead of failing', function () {
    $article = IncomingArticle::fromPayload([
        'title' => ['not', 'a', 'string'],
        'content' => 12,
        'keywords' => ['seo', ['nested'], 7],
        'meta_description' => false,
        'cms_post_id' => ' 42 ',
        'status' => 'PUBLISH',
    ]);

    expect($article->title)->toBe('')
        ->and($article->content)->toBe('12')
        ->and($article->keywords)->toBe(['seo', '7'])
        ->and($article->remoteId)->toBe('42')
        ->and($article->isPublished())->toBeTrue();
});
