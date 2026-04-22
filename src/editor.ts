import { mount } from 'svelte';
import FileEditor from './components/Editor/FileEditor.svelte';
import './app.css';

const target = document.getElementById('anibas-file-editor-app');
if (!target) {
    console.error('Target element #anibas-file-editor-app not found');
} else {
    mount(FileEditor, { target });
}
