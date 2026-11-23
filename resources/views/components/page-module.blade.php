@props(['name'])
{{--
    Says which script module of resources/js/pages/ this page needs (<x-page-module name="companies" />,
    inside the view's @push('scripts')). It is a hidden marker, not a <script>: resources/js/ui/soft-nav.js
    reads it, imports the module and starts it on every visit, a full page load or a soft navigation.
    The module must `export default` its start function.
--}}
<span hidden data-page-module="{{ $name }}"></span>
