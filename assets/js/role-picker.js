// PFAD: /assets/js/role-picker.js

document.addEventListener("DOMContentLoaded",()=>{

document.querySelectorAll(".role-add").forEach(btn=>{

btn.addEventListener("click",(e)=>{

e.stopPropagation();

const menu=document.createElement("div");

menu.className="role-picker-menu";
menu.innerHTML=`
<div class="role-picker-item select-server">
Select from Server
</div>

<div class="role-picker-item add-by-id">
Add by ID
</div>
`;

btn.parentElement.appendChild(menu);

menu.querySelector(".select-server").onclick=()=>{
openServerModal();
};

menu.querySelector(".add-by-id").onclick=()=>{
openAddIdModal();
};

});

});

});

function openServerModal(){

const modal=document.getElementById("roleServerModal");
modal.style.display="block";

}

function openAddIdModal(){

const modal=document.getElementById("roleIdModal");
modal.style.display="block";

}