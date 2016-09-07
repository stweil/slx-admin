if (!roomplanner) var roomplanner = {
		
		getScaleFactor: function() {
			return this.settings.scale/100;
		},
		getCellPositionFromPixels: function(left,top) {
			var n = this.settings.scale / this.settings.cellsep;
			return [ parseInt(left) - ((parseInt(left)%n)+n)%n , parseInt(top) - ((parseInt(top)%n)+n)%n];
		},
		getCellPositionFromGrid: function(row,col) {
			var n = this.settings.scale / this.settings.cellsep;
			return [ parseInt(col*n), parseInt(row*n) ]
		},
		getGridFromPixels: function(left,top) {
			var n = this.settings.scale / this.settings.cellsep;
			return [Math.round(top/n), Math.round(left/n)];
		},
		settings: {
			cellsep: 4,
			scale: 100,
			room: {
				width: 1000,
				height: 1000
			}
		},
		selectFromServer: selectMachine,
		isElementResizable: function(el) {
			return (!$(el).attr('noresize') && $(el).attr('itemtype') != 'pc'); 
		},
		initRotation: function(el) {
			if (!(new RegExp(".*(east|south|west|north)$").test($(el).attr('itemlook')))) {
				return;
			}
			
			$(el).append('<div class="rotationHandle glyphicon glyphicon-repeat"></div>');
			$(el).find('.rotationHandle').click(function () {
				var str = $(el).attr('itemlook');
				if (str.indexOf('-') > -1){
					var values =str.split('-');
					var name = values[0];
					var direction = values[1];

					var re = new RegExp("east|south|west|north");
					if (re.test(direction)) {
						var newdirection;
						switch(direction) {
						    case "east":
						    	newdirection = "south";
						        break;
						    case "south":
						    	newdirection = "west";
						        break;
						    case "west":
						    	newdirection = "north";
						        break;
						    case "north":
						    	newdirection = "east";
						        break;
						}
						var result = name + "-" + newdirection;
						$(el).attr('itemlook', result);
					}
				} 
			});
		},
		initDelete: function(el) {
			$(el).append('<div class="deleteHandle glyphicon glyphicon-remove-sign"></div>');
			$(el).find('.deleteHandle').click(function() {
				if ($(this).parent().attr('itemtype') == "pc") {
					var self = this;
					BootstrapDialog.confirm(__('are you sure'),function(result) {
						if (result) {
							$(self).parent().remove();
						}
					});
				} else {
					$(this).parent().remove();
				}
			});
		},
		initTooltip: function(el) {
			if ($(el).attr('itemtype') == 'pc') {
				var tip = "<b>Rechnerdaten</b><br>";
				$(roomplanner.computerAttributes).each(function(i,key){
					tip += __(key)+": "+$(el).attr(key)+"<br>";
				});

				$(el).attr('title', tip);
				$(el).tooltip({html: true});
			}
		},
				
		initDraggable: function(el) {
			$(el).draggable();
			var options = {
					"containment" : "#draw-element-area",
					"helper" : false,
					"grid" : [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)],
					"stop": function(ev,ui) {
						if ($(this).attr("obstacle") == "true") {
							$(this).addClass("obstacle");
						}
						
						if ($(this).attr('itemtype').indexOf('_drag') > -1) {
							var itemtype = $(this).attr('itemtype').replace('_drag','');
							$(this).attr('itemtype',itemtype);
						}
					},
					"preventCollision" : true,
					"restraint": "#draw-element-area",
					"obstacle" : '[itemtype="'+$(el).attr('itemtype')+'"]',
					"start": function(ev,ui) {
						if (roomplanner.isElementResizable(this)) {
							$(this).resizable("option","maxHeight",null);
							$(this).resizable("option","maxWidth",null);
						}
						
						var itemtype = $(this).attr('itemtype');
						$(this).attr('itemtype',itemtype+'_drag');
						
						$(this).removeClass("obstacle");
					}
			};
			
			for (var o in options) {
				$(el).draggable("option",o,options[o]);
			}
		},
		initResizable: function(el) {
			if (!roomplanner.isElementResizable(el)) { return; }
						
			$(el).resizable({
				containment : "#draw-element-area",
				obstacle: '[itemtype="'+$(el).attr('itemtype')+'"]',
				handles: "se",
				autoHide: true,
				grid: [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)],
				resize: function(ev,ui) {
					var gridSteps = $(this).resizable("option","grid");
								
					
					var collides = $(this).collision('[itemtype="'+$(el).attr('itemtype').replace('_drag','')+'"]');
					
					
					
					var pos = $(this).offset();
					var self = this;
					
					var mw = $(this).resizable("option","maxWidth");
					var mh = $(this).resizable("option","maxHeight");
					
					var hLimit = ($(this).attr('scalable') == 'v');
					var vLimit = ($(this).attr('scalable') == 'h');
					
					if(collides.length) {
						$(collides).each(function(idx,item) {
							var itempos = $(item).offset();
							
							if (!hLimit) {
								if (pos.left < itempos.left && (pos.left + $(self).width()) > itempos.left) {
									$(self).resizable("option","maxWidth",parseInt(itempos.left - pos.left));

								} else {
									$(self).resizable("option","maxWidth",null);
								}
							}
							
							if (!vLimit) {
								if (pos.top < itempos.top && pos.top + $(self).height() > itempos.top) {
									$(self).resizable("option","maxHeight",parseInt(itempos.top - pos.top));
								} else {
									$(self).resizable("option","maxHeight",null);
								}
							}
						});
					} else {
						if (!hLimit && (mw == null || mw > $(this).width())) {
							$(this).resizable("option","maxWidth",null);
						}						
						if (!vLimit && (mh == null || mh > $(this).height())) {
							$(this).resizable("option","maxHeight",null);
						}
					}
				},
				start: function(ev,ui) {
					$(this).removeClass("obstacle");
					
					var itemtype = $(this).attr('itemtype');
					$(this).attr('itemtype',itemtype+'_drag');
					
					$(this).css('opacity',0.8);
					
					var gridSteps = $(this).resizable("option","grid");
					
					$(this).resizable("option",{
						minHeight: gridSteps[1]*roomplanner.getScaleFactor(),
						minWidth: gridSteps[0]*roomplanner.getScaleFactor()
					});
					
					if ($(this).attr('scalable')) {
						switch ($(this).attr('scalable')) {
						case 'h':
							$(this).resizable("option",{
								minHeight: $(this).height(),
								maxHeight: $(this).height()
							});
							break;
						case 'v':
							$(this).resizable("option",{
								minWidth: $(this).width(),
								maxWidth: $(this).width()
							});
							break;
						}
					}
					
				},
				stop: function(ev,ui) {
					if ($(this).attr("obstacle") == "true") {
						$(this).addClass("obstacle");
					}
					
					if ($(this).attr('itemtype').indexOf('_drag') > -1) {
						var itemtype = $(this).attr('itemtype').replace('_drag','');
						$(this).attr('itemtype',itemtype);
					}
					
					var gridSteps = $(this).resizable("option","grid");
					var mw = $(this).resizable("option","maxWidth");
					if (mw) {
						$(this).width(mw);
					} else {
						$(this).width($(this).outerWidth() - $(this).outerWidth()%(gridSteps[0]));
					}
					
					var mh = $(this).resizable("option","maxHeight");
					if (mh) {
						$(this).height(mh);
					} else {
						$(this).height($(this).outerHeight() - $(this).outerHeight()%(gridSteps[1]));
					}

					
					$(this).attr('data-width', $(this).outerWidth()/roomplanner.getScaleFactor() - (($(this).outerWidth()%gridSteps[0])/roomplanner.getScaleFactor()));
					$(this).attr('data-height', $(this).outerHeight()/roomplanner.getScaleFactor() - (($(this).outerHeight()%gridSteps[1])/roomplanner.getScaleFactor()));
				
					
					$(this).css('opacity',1);
				}
			});
		},
		serialize: function() {
			
			var objects = {
					"furniture": [],
					"computers": []
			};
			
			var furniture = $('#draw-element-area div[itemtype="furniture"]');
			furniture.each(function(idx,el) {
				objects.furniture.push({
					"gridRow" : $(el).attr('gridRow'),
					"gridCol" : $(el).attr('gridCol'),
					"data-width": $(el).attr('data-width'),
					"data-height": $(el).attr('data-height'),
					"itemlook": $(el).attr('itemlook'),
				});
			});
			
			var computers = $('#draw-element-area div[itemtype="pc"]');
			computers.each(function(idx,el) {
				
				var object = {					
						"gridRow" : $(el).attr('gridRow'),
						"gridCol" : $(el).attr('gridCol'),
						"data-width": $(el).attr('data-width'),
						"data-height": $(el).attr('data-height'),
						"itemlook": $(el).attr('itemlook'),
						"muuid": $(el).attr('muuid')
					};
												
				objects.computers.push(object)
			});
			
			
			return JSON.stringify(objects);
		},
		load: function(object) {
			if (typeof object === 'string') {
				try {
					var objects = JSON.parse(object);
				} catch (e) {
					alert('invalid JSON format');
					return false;
				}
			} else {
				var objects = object;
			}
			
			$('#draw-element-area').html('');
			
			function itemToHtml(item, itemtype, obstacle) {
				var html = '<div itemtype="'+itemtype+'" style="position:absolute;"  ';
				for (var prop in item) {
					if (!item.hasOwnProperty(prop)) continue;
					html += prop+'="'+item[prop]+'" ';
				}
				html += 'class="draggable ui-draggable';
				
				if (obstacle) {
					html += " obstacle";
				}
					
				html+= '"></div>';
				return html; 
			}
			
			if (objects.furniture) {
				var furniture = objects.furniture;
				for (var piece in furniture) {
					var item = itemToHtml(furniture[piece], "furniture", true);
					$('#draw-element-area').append(item);
					
				}
			}
			
			
			if (objects.computers) {
				var computers = objects.computers;
				for (var piece in computers) {
					var item = itemToHtml(computers[piece], "pc", false);
					$('#draw-element-area').append(item);
				}
			}
			
			$('#draw-element-area .draggable').each(function(idx,el) {
				roomplanner.initDraggable(el);
				roomplanner.initResizable(el);
				roomplanner.initTooltip(el);
				roomplanner.initRotation(el);
				roomplanner.initDelete(el);
			});
			
			roomplanner.grid.scale(roomplanner.settings.scale);
		},
		clear: function() {
			$('#draw-element-area').html('');
		}
};

roomplanner.grid = (function() {
		var grid = {
			resize: function() {
				var w = Math.max($('#drawpanel .panel-body').width(),roomplanner.settings.room.width*roomplanner.settings.scale)
				var h = Math.max($('#drawpanel .panel-body').height(),roomplanner.settings.room.height*roomplanner.settings.scale)
				$('#drawarea').width(w);
				$('#drawarea').height(h);
			},
			scale: function(num) {
				
				var area_left = parseInt($('#drawarea').css('left')) - $('#drawpanel .panel-body').width()/2 ;
				var area_top = parseInt($('#drawarea').css('top')) - $('#drawpanel .panel-body').height()/2;
				
				var opts = {
						left: ((parseInt(area_left) * num / roomplanner.settings.scale ) + $('#drawpanel .panel-body').width()/2)+ "px" ,
						top: ((parseInt(area_top)  * num / roomplanner.settings.scale ) + $('#drawpanel .panel-body').height()/2)+ "px" 
					};
				
				$('#drawarea').css(opts);
				
				
				$('#drawarea').css('background-size',num);
				roomplanner.settings.scale = num;
				$('#draw-element-area .ui-draggable').each(function(idx,item) {
					var h = $(item).attr('data-height') * roomplanner.getScaleFactor();
					var w = $(item).attr('data-width') * roomplanner.getScaleFactor();
					//var pos = roomplanner.getCelloffset()
					
					var l = parseInt($(item).css('left')) * roomplanner.getScaleFactor();
					var t = parseInt($(item).css('top')) * roomplanner.getScaleFactor();
					
					var pos = roomplanner.getCellPositionFromGrid($(item).attr('gridRow'),$(item).attr('gridCol'));
					
					$(item).css({width: w+"px", height: h+"px", left: pos[0]+"px", top: pos[1]+"px"});
					$(item).draggable("option","grid",[(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)]);
					
					if (roomplanner.isElementResizable(item)) {
						$(item).resizable("option","grid",[(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)]);
					}
				});
				this.resize();
			},
			init: function() {
				this.resize();
				$(window).resize($.proxy(function(){
					this.resize();
				},this));
			}
		}
		
		return grid;
}
)();

$(document).ready(function(){
	roomplanner.grid.init();

	var update = function(event,ui) {
		roomplanner.grid.scale(ui.value);
	};
	
	roomplanner.slider = $('#scaleslider').slider({
		orientation: "horizontal",
		range: "min",
		min: 50,
		max: 150,
		value: 100,
		change: update,
		slide: update,
		stop: function(e, ui) {
			$('#drawarea').trigger('checkposition');
		}
	
	});
	
	$('#drawarea').bind('checkposition', function() {
		if ($(this).offset().left > 0) {
			$(this).css('left',0);
		}
		if (parseInt($(this).css('top')) > 0) {
			$(this).css('top',0);
		}
		
		if (($(this).width() + parseInt($(this).css('left'))) < $(this).parent().width()) {
			$(this).css('left', ($(this).parent().width() - $(this).width()));
		}
		
		if (($(this).height() + parseInt($(this).css('top'))) < $(this).parent().height()) {
			$(this).css('top', ($(this).parent().height() - $(this).height()));
		}
	});
	
	$('#drawarea').draggable({
		stop: function() {
			$(this).trigger('checkposition');
		}
	});
	
	$('#draw-element-area').droppable({
		accept: ".draggable",
		drop: function(event, ui) {
			
			// the element is already in drawing area
			var el = (ui.helper == ui.draggable) ? ui.draggable : $(ui.helper.clone());
			
			var collidingElements = $(el).collision('[itemtype="'+$(el).attr('itemtype').replace('_drag','')+'"]');
			
			var i = 0;
			while (collidingElements.length > 0) {
				// too much tries - abort
				if (i > 5) { return; }
				
				
				if (ui.helper != ui.draggable) {
					var leftPos = parseInt($(el).css('left'))-parseInt($('#drawarea').css('left'))-$('#drawpanel').offset().left;
					var topPos = parseInt($(el).css('top'))-parseInt($('#drawarea').css('top'))-($('#drawpanel').offset().top + $('#drawpanel .panel-heading').height());
					var cp = roomplanner.getCellPositionFromPixels(leftPos,topPos);
					leftPos = cp[0];
					topPos = cp[1];
				
				} else {
					var leftPos = parseInt($(el).css('left'));
					var topPos = parseInt($(el).css('top'));
				}
				
				var collider = $(collidingElements[0]);
				var colliderTop = parseInt(collider.css('top'));
				var colliderLeft = parseInt(collider.css('left'));
				
				var overlap = {
						x: Math.min(colliderLeft+collider.outerWidth(),leftPos+$(el).outerWidth()) - Math.max(leftPos,colliderLeft),
						y: Math.min(colliderTop+collider.outerHeight(),topPos+$(el).outerHeight()) - Math.max(topPos,colliderTop)
				};
				
				if (overlap.x <= overlap.y) {
					var lpos = parseInt($(el).css('left'));
					if (colliderLeft + overlap.x == leftPos + $(el).width()) {
						$(el).css('left',(lpos - (overlap.x+2))+"px");
					} else {
						$(el).css('left',(lpos + overlap.x+2)+"px");
					}
				} else {
					var tpos = parseInt($(el).css('top'));
					if (colliderTop + overlap.y == topPos + $(el).height()) {
						$(el).css('top',(tpos - (overlap.y+2))+"px");
					} else {
						$(el).css('top',(tpos + overlap.y+2)+"px");
					}
				}
				collidingElements = $(el).collision('[itemtype="'+$(el).attr('itemtype').replace('_drag','')+'"]');
					
				i++;
			}
			
			var itemtype = $(el).attr('itemtype');
			$(el).attr('itemtype',itemtype.replace('_drag',''));
			
			$(el).removeClass('collides');
			$(el).css('opacity',1);
			
			if (ui.helper != ui.draggable) {
				var l = parseInt($(el).css('left'))-parseInt($('#drawarea').css('left'))-$('#drawpanel').offset().left;
				var t = parseInt($(el).css('top'))-parseInt($('#drawarea').css('top'))-($('#drawpanel').offset().top + $('#drawpanel .panel-heading').height());
				var cp = roomplanner.getCellPositionFromPixels(l,t);
				$(el).css('left',cp[0]);
				$(el).css('top',cp[1]);
			}
			
			var gridPositions = roomplanner.getGridFromPixels(parseInt($(el).css('left')),parseInt($(el).css('top')));
			$(el).attr('gridRow',gridPositions[0]);
			$(el).attr('gridCol',gridPositions[1]);
			
			if ($(el).attr("obstacle") == "true") {
				$(el).addClass("obstacle");
			}
			
			roomplanner.initResizable(el);
			roomplanner.initDraggable(el);
			
			if (ui.helper != ui.draggable) {
				$(this).append(el);
				
				if ($(el).attr('itemtype') == "pc") {
					
					var uuids = [];
					var computers = $('#draw-element-area div[itemtype="pc"]');
					computers.each(function(idx,el) {
						if ($(el).attr('muuid')) {
							uuids.push($(el).attr('muuid'));
						}
					});
					
					roomplanner.selectFromServer(uuids, function (result) {
						if (!result) {
							$(el).remove();
						} else {
							for (var key in result) {
								$(el).attr(key,result[key]);
							}
							roomplanner.initTooltip(el);
						}
					});
				}			
				roomplanner.initDelete(el);				
				roomplanner.initRotation(el);
				
			}
			
		}
	});
	
	$('.draggable').draggable({
		helper: "clone",
		//grid : [(roomplanner.settings.scale / 4), (roomplanner.settings.scale / 4)],
		preventCollision: true,
		restraint: "#draw-element-area",
		obstacle: ".obstacle",
		cursorAt: {left:5,top:5},
		start: function(ev,ui) {
				$(ui.helper).css('opacity',0.8);
				$(ui.helper).height($(this).attr('data-height')*roomplanner.getScaleFactor());
				$(ui.helper).width($(this).attr('data-width')*roomplanner.getScaleFactor());
				var type = $(ui.helper).attr('itemtype');
				$(ui.helper).attr('itemtype',type+"_drag");
		},
		drag: function(ev,ui) { 
			if ($(ui.helper).collision('[itemtype="'+$(ui.helper).attr('itemtype').replace('_drag','')+'"]').length) {
				$(ui.helper).addClass('collides');
			} else {
				$(ui.helper).removeClass('collides');
			}
		}
	})
	
});
