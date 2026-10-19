<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Tree Select Input Version</title>

<style>
    body{
        font-family:Arial,sans-serif;
        padding:20px;
    }

    /* ===== INPUT + TAG STYLE ===== */
    .input-wrapper{
        border:1px solid #ccc;
        border-radius:6px;
        padding:5px;
        display:flex;
        flex-wrap:wrap;
        gap:5px;
        min-height:42px;
    }

    .tag{
        display:flex;
        align-items:center;
        gap:5px;
        background:#eaeaea;
        border-radius:20px;
        padding:3px 8px;
        font-size:13px;
    }

    .tag button{
        border:none;
        background:none;
        color:red;
        cursor:pointer;
        font-weight:bold;
    }

    #searchBox{
        border:none;
        outline:none;
        flex:1;
        min-width:120px;
    }

    /* ===== TREE ===== */
    .tree ul{
        list-style:none;
        padding-left:20px;
    }

    .tree li{
        margin:4px 0;
    }

    .toggle{
        cursor:pointer;
        width:18px;
        display:inline-block;
    }

    /* ===== SEARCH HIGHLIGHT ===== */
    .highlight{
        background:yellow;
    }
</style>
</head>

<body>

<h3>Selected</h3>

<div class="input-wrapper" id="inputWrapper">
    <input type="text" id="searchBox" placeholder="Search...">
</div>

<input type="hidden" id="selectedInput">

<div id="tree" class="tree"></div>

<script>
    // ================= DATA =================
    const treeData = [
    {
        id:1,
        text:"Electronics",
        children:[
            { id:11, text:"Laptop" },
            { id:12, text:"Mobile Phone" },
            { id:13, text:"Tablet" }
        ]
    },
    {
        id:2,
        text:"Furniture",
        children:[
            { id:21, text:"Table" },
            { id:22, text:"Chair" }
        ]
    },
    {
        id:3,
        text:"Food",
        children:[
            { id:31, text:"Pizza" },
            { id:32, text:"Burger" }
        ]
    }
    ];

    // ================= STATE =================
    const selected = new Map();

    // ================= TAG UI =================
    const wrapper = document.getElementById('inputWrapper');
    const searchBox = document.getElementById('searchBox');
    const hiddenInput = document.getElementById('selectedInput');

    function renderTags(){

        // remove old tags
        document.querySelectorAll('.tag').forEach(t => t.remove());

        selected.forEach((text, id) => {

            const tag = document.createElement('div');
            tag.className = 'tag';

            tag.innerHTML = `
                <span>${text}</span>
                <button type="button">✕</button>
            `;

            tag.querySelector('button').onclick = () => {

                selected.delete(id);

                const cb = document.querySelector(`input[value="${id}"]`);
                if(cb){
                    cb.checked = false;
                    cb.indeterminate = false;
                }

                syncInput();
                renderTags();
            };

            wrapper.insertBefore(tag, searchBox);
        });

        syncInput();
    }

    function syncInput(){
        hiddenInput.value = [...selected.keys()].join(',');
    }

    // ================= TREE =================
    function createTree(nodes){

        const ul = document.createElement('ul');

        nodes.forEach(node => {

            const li = document.createElement('li');
            const hasChild = node.children && node.children.length;

            li.innerHTML = `
                ${hasChild ? '<span class="toggle">▶</span>' : '<span class="toggle"></span>'}
                <label>
                    <input type="checkbox"
                        value="${node.id}"
                        data-text="${node.text}">
                    <span class="label-text">${node.text}</span>
                </label>
            `;

            if(hasChild){
                const child = createTree(node.children);
                child.style.display = "none";
                li.appendChild(child);
            }

            ul.appendChild(li);
        });

        return ul;
    }

    document.getElementById('tree')
        .appendChild(createTree(treeData));

    // ================= EXPAND =================
    document.addEventListener('click', e => {

        if(!e.target.classList.contains('toggle')) return;

        const ul = e.target.parentElement.querySelector(':scope > ul');
        if(!ul) return;

        if(ul.style.display === "none"){
            ul.style.display = "block";
            e.target.textContent = "▼";
        }else{
            ul.style.display = "none";
            e.target.textContent = "▶";
        }
    });

    // ================= CHECKBOX LOGIC =================
    document.addEventListener('change', e => {

        if(e.target.type !== 'checkbox') return;

        const cb = e.target;
        const li = cb.closest('li');

        const id = cb.value;
        const text = cb.dataset.text;

        if(cb.checked){
            selected.set(id, text);
        }else{
            selected.delete(id);
        }

        // child check all
        li.querySelectorAll('input[type=checkbox]')
            .forEach(child => {

                child.checked = cb.checked;

                if(cb.checked){
                    selected.set(child.value, child.dataset.text);
                }else{
                    selected.delete(child.value);
                }
            });

        updateParent(cb);
        renderTags();
    });

    // ================= PARENT STATE =================
    function updateParent(cb){

        const parentLi = cb.closest('ul')?.parentElement;
        if(!parentLi) return;

        const parent = parentLi.querySelector(':scope > label input[type=checkbox]');
        if(!parent) return;

        const children = parentLi.querySelectorAll(':scope > ul input[type=checkbox]');

        const checked = [...children].filter(c => c.checked);

        if(checked.length === 0){
            parent.checked = false;
            parent.indeterminate = false;
        }
        else if(checked.length === children.length){
            parent.checked = true;
            parent.indeterminate = false;
        }
        else{
            parent.checked = false;
            parent.indeterminate = true;
        }

        updateParent(parent);
    }

    // ================= SEARCH =================
    searchBox.addEventListener('input', function(){

        const keyword = this.value.toLowerCase();

        document.querySelectorAll('.tree li').forEach(li => {

            const label = li.querySelector('.label-text');
            const text = label?.textContent.toLowerCase() || '';

            const match = text.includes(keyword);

            li.style.display = match ? '' : 'none';

            if(label){
                label.innerHTML = label.textContent;

                if(keyword && match){
                    label.innerHTML = label.textContent.replace(
                        new RegExp(keyword, 'gi'),
                        m => `<span class="highlight">${m}</span>`
                    );
                }
            }

            if(keyword && match){
                const ul = li.querySelector(':scope > ul');
                if(ul) ul.style.display = "block";
            }
        });
    });

</script>

</body>
</html>