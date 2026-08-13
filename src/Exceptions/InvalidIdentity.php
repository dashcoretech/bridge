<?php

namespace Dashcore\Bridge\Exceptions;

/**
 * This application cannot say who it is.
 *
 * Raised only by the enrolment path, where continuing would mint a credential
 * nobody can attribute — an identity several applications could equally claim
 * is not an identity, and the collision it produces is silent: the next app to
 * enrol simply takes over the first one's credential.
 *
 * Deliberately not raised during signing. An app whose APP_URL goes missing
 * after enrolment keeps running on the credential it already holds rather than
 * failing every outbound request.
 */
class InvalidIdentity extends BridgeException {}
