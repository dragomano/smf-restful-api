# SMF RESTful API

[![SMF 2.1](https://img.shields.io/badge/SMF-2.1-ed6033.svg?style=flat)](https://github.com/SimpleMachines/SMF2.1)
![License](https://img.shields.io/github/license/dragomano/smf-restful-api)
![Hooks only: Yes](https://img.shields.io/badge/Hooks%20only-YES-blue)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/smf-restful-api/badge.svg?branch=main)](https://coveralls.io/github/dragomano/smf-restful-api?branch=main)

**SMF RESTful API** is a modification for the [SMF 2.1](https://github.com/SimpleMachines/SMF2.1)
forum that layers a full-featured REST interface with JSON responses on top of the
engine.

The mod registers a single action, `index.php?action=api`, and passes the
request to its own router. A resource is addressed as
`index.php?action=api;path=v1/members/5`, or, with a rewrite rule in place, as
`/api/v1/members/5`.

[Full documentation](https://dragomano.github.io/smf-restful-api/) — getting started guide and API reference.
