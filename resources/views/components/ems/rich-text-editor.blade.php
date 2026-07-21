@props(['name','value'=>'','placeholder'=>'','required'=>false])
@php
    $initial=str_contains((string)$value,'<') ? (string)$value : nl2br(e((string)$value));
@endphp
<div class="gpha-rich-editor" x-data="{value:@js($initial),run(command){document.execCommand(command,false,null);this.$refs.editor.focus();this.value=this.$refs.editor.innerHTML}}" x-init="$refs.editor.innerHTML = value">
    <div class="gpha-rich-toolbar" role="toolbar" aria-label="Text formatting">
        <button type="button" title="Bold" aria-label="Bold" @mousedown.prevent="run('bold')"><strong>B</strong></button>
        <button type="button" title="Italic" aria-label="Italic" @mousedown.prevent="run('italic')"><em>I</em></button>
        <button type="button" title="Underline" aria-label="Underline" @mousedown.prevent="run('underline')"><u>U</u></button>
        <span class="gpha-rich-divider"></span>
        <button type="button" title="Bulleted list" aria-label="Bulleted list" @mousedown.prevent="run('insertUnorderedList')">• List</button>
        <button type="button" title="Numbered list" aria-label="Numbered list" @mousedown.prevent="run('insertOrderedList')">1. List</button>
    </div>
    <div x-ref="editor" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="{{ $placeholder }}" @if($required) data-rich-required="true" @endif @input="value=$refs.editor.innerHTML" class="gpha-rich-content"></div>
    <textarea name="{{ $name }}" x-model="value" class="sr-only" tabindex="-1"></textarea>
</div>
