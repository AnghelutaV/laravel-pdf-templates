<?php

namespace AnghelutaV\PdfTemplates\Exceptions;

// The Node renderer could not be reached (not started, wrong PDFME_RENDERER_URL, timeout).
// Sent to the admin as code "renderer_down" so the message can be muted there.
class RendererUnavailableException extends RenderException {}
