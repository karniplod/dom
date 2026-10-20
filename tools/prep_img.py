import os, sys
from PIL import Image, ImageOps, ImageEnhance
import pathlib
B=str(pathlib.Path(__file__).resolve().parent.parent / 'images')
E=B+'/Английский дом'; U=B+'/Русский дом'; S=B+'/Дом на свиязева'
import glob
def pick(folder, name):
    return folder + '/' + name  # файлы по имени: порядок в папке может меняться
M={
 'ru':{'hero':(B,'IMG_20261019_150656.jpg'),'hero-2':(U,'IMG_20261019_150617.jpg'),'facade':(U,'IMG_20261019_150617.jpg'),'facade-2':(U,'aec78084dc5071ce83fa8e3eef495a06.jpg'),'kitchen':(U,'df65ea9384d3437ac341dbd303d279c3.jpg'),'pool':(U,'e6f2b45c18c6c10bc6e2fd6c3aca8683.jpg'),'pool-2':(U,'IMG_20261019_150528.jpg'),'billiard':(U,'fb3d948ed8a912234503e6d084bc2ec0.jpg'),'billiard-2':(U,'188ede6a0a6d6eba6cddd7d176311280.jpg'),'bedroom':(U,'667382a36e96e2cbaf1f976ca186c93f.jpg'),'bedroom-2':(U,'b2504b04cd9780abee96ce3809d048fe.jpg'),'sauna':(U,'IMG_20261019_150550.jpg'),'dining':(U,'IMG_20261019_150702.jpg'),'shower':(U,'3a6f38a7f2b6e8a4df5e437bfd70c536.jpg')},
 'en':{'hero':(E,'IMG_20261019_150440.jpg'),'facade':(E,'Оставить заявку.jpg'),'kitchen':(E,'IMG_20261019_150740.jpg'),'dining':(E,'IMG_20261019_150746.jpg'),'fireplace':(E,'946dbb3994a4fc800b8e120f4bb34694.jpg'),'pool':(E,'IMG_20261019_150632.jpg'),'pool-2':(E,'9e3cf5446f45c6754a805c081b647321.jpg'),'pool-3':(B,'IMG_20261019_150514.jpg'),'billiard':(E,'e5c5fb511a5b273c278fdea0f84f2035.jpg'),'billiard-2':(E,'IMG_20261019_150645.jpg'),'sauna':(E,'IMG_20261019_150734.jpg'),'bedroom':(E,'5cbdd91979404ffcf813829b2931210e.jpg'),'gazebo':(E,'IMG_20261019_150743.jpg'),'hall':(E,'IMG_20261019_150718.jpg'),'hall-2':(E,'IMG_20261019_150749.jpg'),'lounge':(B,'IMG_20261019_150606.jpg'),'yard':(B,'IMG_20261019_150730.jpg')},
 'sv':{'hero':(S,'7775fa2a0705abe871e5d1029bf58ed7.jpg'),'facade':(S,'442c1f1c3ca6db4e7837e6fd0fd30460.jpg'),'gazebo':(S,'301d9c218ddde10041f66a64b7c786c4.jpg'),'billiard':(S,'772ca951dfa7d6416943c284429e2b1a.jpg'),'billiard-2':(S,'f3226ae826270e45b73a706b346e679d.jpg'),'dining':(S,'7775fa2a0705abe871e5d1029bf58ed7.jpg'),'sauna':(S,'97a20974e6fa9d9692207ebad563b881.jpg'),'pool':(S,'ae0cf84c971d9cc1c3772e91fb11e89a.jpg'),'bedroom':(S,'b3375bfedcf1b7cc7d46245187a7683a.jpg'),'bedroom-2':(S,'e9196d78470898290e8cb0c0c55ce07b.jpg')},
 'mood':{'party':(B,'595e330be324bdcc728423807f83b693.jpg'),'friends-sauna':(B,'46a29b8ecaf46c6f23ce878d2a411c9a.jpg'),'dinner':(B,'8a40d35b817a88f536ef1bf11f9e1678.jpg'),'jacuzzi':(B,'ea0c341d86e7ccc28900faab9720bd94.jpg'),'sauna-light':(B,'f5b3f282bf37c0efb4fdb496a0d8e33e.jpg'),
         # блок «Для праздника и для отдыха» — картинки-настроение от владельца
         'occ-birthday':(B,'День рождения.png'),'occ-friends':(B,'Баня с друзьями.png'),'occ-corporate':(B,'Корпоратив.png'),'occ-family':(B,'Семейные выходные.png')},
}
out=sys.argv[1]
only=set(sys.argv[2].split(',')) if len(sys.argv)>2 else None  # например: ru/hero-2
for k,d in M.items():
    os.makedirs(f'{out}/{k}',exist_ok=True)
    for name,(f,i) in d.items():
        if only and f'{k}/{name}' not in only: continue
        p=pick(f,i); im=ImageOps.exif_transpose(Image.open(p)).convert('RGB')
        if k=='sv' and name=='facade': im=im.crop((0,0,im.width,int(im.height*0.93)))
        if k=='sv' and name=='facade':
            # кадр из 3D-тура: закрашиваем белую стрелку-переход на двери
            from PIL import ImageFilter, ImageDraw
            box=(382,402,438,458)
            ImageDraw.Draw(im).rectangle(box, fill=(30,27,27))
            patch=im.crop((box[0]-6,box[1]-6,box[2]+6,box[3]+6)).filter(ImageFilter.GaussianBlur(3))
            im.paste(patch.crop((6,6,6+box[2]-box[0],6+box[3]-box[1])),box[:2])
        mx=2000 if name.startswith('hero') else 1400
        if max(im.size)>mx: im.thumbnail((mx,mx),Image.LANCZOS)
        if k!='mood':
            im=ImageOps.autocontrast(im,cutoff=0.5)
            im=ImageEnhance.Color(im).enhance(1.08)
            # warm grade: slight warmth
            r,g,b=im.split(); r=r.point(lambda v:min(255,int(v*1.03))); b=b.point(lambda v:int(v*0.97)); im=Image.merge('RGB',(r,g,b))
        im.save(f'{out}/{k}/{name}.jpg',quality=80,optimize=True,progressive=True)
        print(k,name,im.size,os.path.basename(p))
