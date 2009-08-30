# couchdb-php

*couchdb-php* provides simple API to [CouchDB](http://couchdb.apache.org/), the document-oriented database. Because of lack of CouchDB at some hosting providers, *couchdb-php* adds "emulation" of CouchDB written natively in PHP.

## Getting started

*couchdb-php* consists of API interfaces (`*API.php`). The default implementation of these interfaces is class `CouchDB`.

If you want to connect CouchDB, say it is listening on `localhost` at port `5984`:

    $couchdb = CouchDB::open('fsock://localhost:5984/');

`$couchdb` now provides interface to whole server (UUIDs generation, replication etc.). To work with specific database, call `db()`:

    $testdb = $couchdb->db('test');

You want to create document?

    $foo = $testdb->doc('foo');
    $foo->bar = 'baz';
    $foo->save();

What about attachment?

    $attachment = $foo->attachment('xyz');
    $attachment->content_type = 'text/plain';
    $attachment->data = 'hello, world!';

For more methods look at all `*API` interfaces.

## Connectors

`CouchDB` communicates with database through *connectors*. There are two connectors at the time:

- `CouchDBFsockConnector` -- communicates through HTTP protocol with function [`fsockopen()`](http://php.net/fsockopen)
- `CouchDBFileConnector` -- "emulates" CouchDB, written completely in PHP, uses filesystem

When you call `CouchDB::open()`, you specify which connector you want to use through 
scheme part of URL (`fsock://` -> `CouchDBFsockConnector`, `file://` -> `CouchDBFileConnector`, ...).

## "Emulation" -- CouchDBFileConnector

Firstly, you should use real CouchDB whenever possible.

`CouchDBFileConnector` tries to mimic CouchDB's behaviour. You "connect" to CouchDB
with `CouchDBFileConnector` like this:

    $couchdb = CouchDB::open('file:///path/to/database/dir');

Then work with *APIs as if it were real CouchDB.

### Views

The only language supported is PHP.

#### Examples

##### View like `_all_docs`

    $data = $couchdb->query(
        'function ($doc) {
            emit($doc["_id"], array("rev" => $doc["_rev"]));
        }',
        NULL,
        array(),
        'php'
    );

##### Count all docs

    $data = $couchdb->query(
        'function ($doc) {
            emit(null, 1); 
        }',
        'function ($keys, $values) {
            return array_sum($values);
        }',
        array(),
        'php'
    );

### Incompatibilities with CouchDB

- Does not return `offset` in views (`/<db>/_design/<designname>/_view/<viewname>`, `/<db>/_temp_view`, `/<db>/_all_docs`, ...).
- No `/<db>/_all_docs_by_seq` (returns `not_implemented` error).
- No `/_replicate` (returns `not_implemented` error).
- No `/_stats` (returns `not_implemented` error).
- No `/_config` (returns `not_implemented` error).
- There is no difference between temporary and permanent views (look at code).
- Some errors may differ.

## License

The MIT License

    Copyright (c) 2009 Jakub Kulhan <jakub.kulhan@gmail.com>

    Permission is hereby granted, free of charge, to any person
    obtaining a copy of this software and associated documentation
    files (the "Software"), to deal in the Software without
    restriction, including without limitation the rights to use,
    copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the
    Software is furnished to do so, subject to the following
    conditions:

    The above copyright notice and this permission notice shall be
    included in all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
    EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
    OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
    NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
    HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
    WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
    FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
    OTHER DEALINGS IN THE SOFTWARE.
