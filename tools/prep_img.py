import os, sys
from PIL import Image, ImageOps, ImageEnhance
import pathlib
B=str(pathlib.Path(__file__).resolve().parent.parent / 'images')
E=B+'/Английский дом'; U=B+'/Русский дом'; S=B+'/Дом на свиязева'
import glob
def pick(folder, idx):
    return sorted(glob.glob(folder+'/*.jpg'))[idx]
M={
 'ru':{'hero':(B,7),'hero-2':(U,8),'facade':(U,8),'facade-2':(U,12),'kitchen':(U,14),'pool':(U,15),'pool-2':(U,5),'billiard':(U,17),'billiard-2':(U,0),'bedroom':(U,2),'bedroom-2':(U,13),'sauna':(U,7),'dining':(U,10),'shower':(U,1)},
 'en':{'hero':(E,4),'facade':(E,0),'kitchen':(E,9),'dining':(E,11),'fireplace':(E,2),'pool':(E,5),'pool-2':(E,3),'pool-3':(B,4),'billiard':(E,13),'billiard-2':(E,6),'sauna':(E,8),'bedroom':(E,1),'gazebo':(E,10),'hall':(E,7),'hall-2':(E,12),'lounge':(B,5),'yard':(B,9)},
 'sv':{'hero':(S,3),'facade':(S,1),'gazebo':(S,0),'billiard':(S,2),'billiard-2':(S,8),'dining':(S,3),'sauna':(S,4),'pool':(S,5),'bedroom':(S,6),'bedroom-2':(S,7)},
 'mood':{'party':(B,2),'friends-sauna':(B,1),'dinner':(B,3),'jacuzzi':(B,11),'sauna-light':(B,12)},
}
out=sys.argv[1]
only=set(sys.argv[2].split(',')) if len(sys.argv)>2 else None  # например: ru/hero-2
for k,d in M.items():
    os.makedirs(f'{out}/{k}',exist_ok=True)
    for name,(f,i) in d.items():
        if only and f'{k}/{name}' not in only: continue
        p=pick(f,i); im=ImageOps.exif_transpose(Image.open(p)).convert('RGB')
        if k=='sv' and name=='facade': im=im.crop((0,0,im.width,int(im.height*0.93)))
        mx=2000 if name.startswith('hero') else 1400
        if max(im.size)>mx: im.thumbnail((mx,mx),Image.LANCZOS)
        if k!='mood':
            im=ImageOps.autocontrast(im,cutoff=0.5)
            im=ImageEnhance.Color(im).enhance(1.08)
            # warm grade: slight warmth
            r,g,b=im.split(); r=r.point(lambda v:min(255,int(v*1.03))); b=b.point(lambda v:int(v*0.97)); im=Image.merge('RGB',(r,g,b))
        im.save(f'{out}/{k}/{name}.jpg',quality=80,optimize=True,progressive=True)
        print(k,name,im.size,os.path.basename(p))
